<?php

namespace App\Services\Finance;

use App\Models\Club;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit;
use App\Services\Clubs\ClubAccess;
use App\Services\Training\ClubAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PaymentAttachments
{
    public function __construct(private ClubAccess $access, private ClubAction $actions) {}

    public function upload(Club $club, User $actor, string $key, int $id, array $input): array
    {
        $data = Validator::make($input, ['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,pdf|extensions:jpg,jpeg,png,pdf'])->validate();
        /** @var UploadedFile $file */ $file = $data['file'];
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true), 422, 'Поддерживаются JPG, PNG и PDF.');
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime];
        $hash = hash_file('sha256', $file->getRealPath());
        $size = $file->getSize();
        $createdPath = null;
        $kept = false;
        $target = $club->id.'/'.Str::uuid().'.'.$ext;
        try {
            $result = $this->actions->run($club, $actor, $key, 'claim.attachment', ['claim_id' => $id, 'sha256' => $hash, 'size' => $size, 'mime' => $mime], function () use ($club, $actor, $id) {
                $member = $this->access->member($actor, $club);
                $claim = $this->claim($club, $id);
                abort_unless((int) $claim->membership_id === $member->id, 403, 'Чек прикладывает отправитель заявки.');
            }, function () use ($club, $actor, $id, $file, $mime, $ext, $hash, $size, $target, &$createdPath, &$kept) {
                $kept = false;
                $old = DB::table('payment_attachments')->where('club_id', $club->id)->where('claim_id', $id)->first();
                if ($old) {
                    abort_unless(hash_equals($old->sha256, $hash), 409, 'К заявке уже прикреплён чек. Замена не предусмотрена.');

                    return ['id' => $old->id];
                }
                $createdPath = $target;
                $path = Storage::disk('receipts')->putFileAs((string) $club->id, $file, basename($target));
                abort_unless($path, 500, 'Не удалось сохранить чек.');
                $attachment = DB::table('payment_attachments')->insertGetId(['club_id' => $club->id, 'claim_id' => $id, 'actor_id' => $actor->id, 'path' => $path, 'extension' => $ext, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'created_at' => now()]);
                Audit::record($actor->id, 'payment.cheque.attached', ['claim_id' => $id, 'attachment_id' => $attachment], $club->id);

                $kept = true;

                return ['id' => $attachment];
            });
        } catch (\Throwable $e) {
            // If commit outcome is unknown, retain the file rather than remove a possibly committed attachment.
            try {
                if ($createdPath !== null && ! DB::table('payment_attachments')->where('path', $createdPath)->exists()) {
                    Storage::disk('receipts')->delete($createdPath);
                }
            } catch (\Throwable) {
                // A harmless orphan can be reviewed later; a committed cheque must remain intact.
            }
            throw $e;
        }
        if ($createdPath !== null && ! $kept) {
            Storage::disk('receipts')->delete($createdPath);
        }

        return $result;
    }

    public function download(Club $club, User $actor, int $id)
    {
        $claim = $this->claim($club, $id);
        $member = Membership::where('club_id', $club->id)->where('user_id', $actor->id)->firstOrFail();
        abort_unless((int) $claim->membership_id === $member->id || in_array('finance.view', $this->access->capabilities($member, $club), true), 403, 'Чек доступен отправителю и казначею.');
        $file = DB::table('payment_attachments')->where('club_id', $club->id)->where('claim_id', $id)->first();
        abort_unless($file && Storage::disk('receipts')->exists($file->path), 404);

        return Storage::disk('receipts')->download($file->path, 'cheque-'.$id.'.'.$file->extension, ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => 'sandbox']);
    }

    private function claim(Club $club, int $id): object
    {
        $row = DB::table('payment_claims')->where('club_id', $club->id)->find($id);
        abort_unless($row, 404);

        return $row;
    }
}

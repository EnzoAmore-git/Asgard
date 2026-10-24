<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

class HashedDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function read($sessionId): string|false
    {
        return parent::read(hash('sha256', $sessionId));
    }

    public function write($sessionId, $data): bool
    {
        $hash = hash('sha256', $sessionId);
        if (! $this->exists) {
            parent::read($hash);
        }
        $payload = $this->getDefaultPayload($data);
        if ($this->exists) {
            $this->performUpdate($hash, $payload);
        } else {
            $this->performInsert($hash, $payload);
        }

        return $this->exists = true;
    }

    public function destroy($sessionId): bool
    {
        return parent::destroy(hash('sha256', $sessionId));
    }
}

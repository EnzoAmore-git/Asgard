import { useEffect, useRef, useState, type FormEvent } from 'react';
import { createRoot } from 'react-dom/client';
import { Finance } from './Finance';

type Provider = 'telegram' | 'vk';
type Session = { user: { id: number; name: string } | null; identities: Provider[]; providers: Record<Provider, boolean>; csrf_token: string; error: string | null; local_access: boolean };
type Club = { id: number; name: string; membership_id: number; club_name: string; status: string; owner_membership_id: number; capabilities: string[] };
type Member = { id: number; club_name: string; status: string; owner: boolean; permissions: string[]; frozen_until: string | null };
type Invite = { id: number; expires_at: string };
const permissionNames: Record<string, string> = { 'members.manage': 'Управлять участниками', 'schedule.manage': 'Вести расписание', 'finance.manage': 'Вести взносы и казну', 'cash.receive': 'Принимать наличные' };
const statusNames: Record<string, string> = { active: 'В клубе', frozen: 'На паузе', excluded: 'Исключён' };
const providerNames = { telegram: 'Telegram', vk: 'ВКонтакте' };

function App() {
  const [session, setSession] = useState<Session | null>(null);
  const [clubs, setClubs] = useState<Club[]>([]);
  const [clubId, setClubId] = useState<number | null>(null);
  const [screen, setScreen] = useState<'club' | 'account' | 'finance'>('club');
  const [members, setMembers] = useState<Member[]>([]);
  const [nextPage, setNextPage] = useState<number | null>(null);
  const [invites, setInvites] = useState<Invite[]>([]);
  const [newInvite, setNewInvite] = useState('');
  const [selected, setSelected] = useState<Member | null>(null);
  const [rights, setRights] = useState<string[]>([]);
  const [status, setStatus] = useState('active');
  const [reason, setReason] = useState('');
  const [until, setUntil] = useState('');
  const [confirmTransfer, setConfirmTransfer] = useState(false);
  const [busy, setBusy] = useState(false);
  const [loadingClub, setLoadingClub] = useState(false);
  const [bootError, setBootError] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [invitation, setInvitation] = useState<{ club_name: string; expires_at: string } | null>(null);
  const [clubName, setClubName] = useState('');
  const token = /^\/invite\/([A-Za-z0-9]{64})$/.exec(location.pathname)?.[1];
  const club = clubs.find(c => c.id === clubId);
  const can = (p: string) => !!club?.capabilities.includes(p);
  const loadVersion = useRef(0);

  async function request<T>(path: string, method = 'GET', body?: unknown, extraHeaders: Record<string, string> = {}): Promise<T> {
    const response = await fetch(path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': session?.csrf_token ?? '', ...extraHeaders }, body: body === undefined ? undefined : JSON.stringify(body) });
    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      if (response.status === 401) { void boot(); throw new Error('Войдите снова, чтобы продолжить.'); }
      if (response.status === 419) { void boot(); throw new Error('Страница устарела. Попробуйте ещё раз.'); }
      const field = Object.values(data.errors ?? {}).flat()[0];
      throw new Error(response.status >= 500 ? 'Не удалось выполнить действие. Попробуйте позже.' : String(field ?? data.message ?? 'Действие недоступно.'));
    }
    return response.status === 204 ? undefined as T : response.json();
  }
  async function boot() {
    setBootError(false);
    try {
      const current = await request<Session>('/api/me'); setSession(current);
      if (current.error) setError(current.error);
      if (current.user) { const data = await request<Club[]>('/api/clubs'); setClubs(data); setClubId(old => data.some(c => c.id === old) ? old : data[0]?.id ?? null); }
      else { setClubs([]); setClubId(null); }
    } catch { setBootError(true); }
  }
  useEffect(() => { void boot(); }, []);
  useEffect(() => { if (token) request<{club_name: string; expires_at: string}>('/api/invitations/' + token).then(setInvitation).catch(e => setError(e.message)); }, [token]);
  useEffect(() => {
    const version = ++loadVersion.current;
    setMembers([]); setInvites([]); setSelected(null); setNewInvite(''); setConfirmTransfer(false);
    if (!club || club.status === 'excluded') { setLoadingClub(false); return; }
    setLoadingClub(true);
    const tasks = [
      can('members.view') ? request<{data: Member[]; next_page: number | null}>(`/api/clubs/${club.id}/members`) : Promise.resolve({data: [], next_page: null}),
      can('invitations.manage') ? request<Invite[]>(`/api/clubs/${club.id}/invitations`) : Promise.resolve([]),
    ] as const;
    Promise.all(tasks).then(([roster, links]) => { if (version === loadVersion.current) { setMembers(roster.data); setNextPage(roster.next_page); setInvites(links); } })
      .catch(e => { if (version === loadVersion.current) setError(e.message); })
      .finally(() => { if (version === loadVersion.current) setLoadingClub(false); });
  }, [club?.id, club?.capabilities.join(','), club?.status]);

  async function action(task: () => Promise<void>) {
    if (busy) return;
    setBusy(true); setError(''); setNotice('');
    try { if (!navigator.onLine) throw new Error('Нет интернета. Подключитесь к сети и повторите действие.'); await task(); }
    catch (e) { setError(e instanceof Error ? e.message : 'Не удалось выполнить действие.'); }
    finally { setBusy(false); }
  }
  async function refreshClub() {
    const updated = await request<Club[]>('/api/clubs'); setClubs(updated);
    const current = updated.find(c => c.id === clubId);
    if (current?.capabilities.includes('members.view')) { const data = await request<{data: Member[]; next_page: number | null}>(`/api/clubs/${current.id}/members`); setMembers(data.data); setNextPage(data.next_page); }
    else { setMembers([]); setNextPage(null); }
    setSelected(null);
  }
  function pick(member: Member) { setSelected(member); setRights(member.permissions); setStatus(member.status); setReason(''); setUntil(''); setConfirmTransfer(false); }
  const messages = <>{error && <div className="feedback error" role="alert">{error}<button className="quiet" aria-label="Закрыть сообщение" onClick={() => setError('')}>Закрыть</button></div>}{notice && <div className="feedback" role="status">{notice}</div>}</>;
  if (bootError) return <main className="loading"><h1>Не удалось открыть Асгард</h1><p>Проверьте подключение и попробуйте ещё раз.</p><button onClick={() => void boot()}>Повторить</button></main>;
  if (!session) return <main className="loading" role="status">Открываем Асгард…</main>;
  return <>
    <a className="skip" href="#main">К содержимому</a>
    <header className="top"><a className="brand" href="/"><img src="/brand/asgard-drakkar.svg" alt="" width="42" height="42"/><span>Асгард<small>Клубы исторической реконструкции</small></span></a><span className="early">{session.local_access ? 'Локальная проверка' : 'Ранний доступ'}</span></header>
    {!session.user ? <main id="main" className="sign-in">
      <p className="eyebrow">Свой клуб. Свои люди.</p><h1>Добро пожаловать<br/>в Асгард</h1>
      <p className="lead">Войдите, чтобы присоединиться к клубу и быть в курсе его дел.</p>
      {token && invitation && <div className="invitation-note">Вас приглашают в клуб <strong>{invitation.club_name}</strong>. После входа выберите клубное имя.</div>}
      {messages}
      <div className="sign-buttons">{(['telegram','vk'] as Provider[]).map(p => session.providers[p] ? <a className="button" key={p} href={`/auth/${p}/start`}>Войти через {providerNames[p]}</a> : <div className="unavailable" key={p}><button disabled>{providerNames[p]}</button><span>Этот способ входа пока недоступен</span></div>)}</div>
      <p className="hint">Уже входили раньше? Выберите прежний способ. Второй можно привязать в настройках аккаунта.</p>
      <footer>Если у вас нет приглашения, попросите ссылку у своего клуба.</footer>
    </main> : <div className="workspace">
      <aside className="sidebar"><label>Мой клуб<select value={clubId ?? ''} onChange={e => {setClubId(Number(e.target.value)); setScreen('club'); setError(''); setNotice('');}} disabled={!clubs.length || busy}>{!clubs.length && <option value="">Пока нет клубов</option>}{clubs.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><nav aria-label="Разделы"><button className={screen === 'club' ? 'nav active' : 'nav'} aria-current={screen === 'club' ? 'page' : undefined} onClick={() => setScreen('club')}>Мой клуб</button><button className={screen === 'account' ? 'nav active' : 'nav'} aria-current={screen === 'account' ? 'page' : undefined} onClick={() => setScreen('account')}>Аккаунт</button><button className={`nav ${screen === 'finance' ? 'active' : ''}`} onClick={() => setScreen('finance')} disabled={!club}>Взносы</button></nav><p className="identity">{session.user.name}</p><button className="quiet" disabled={busy} onClick={() => void action(async () => {await request('/api/logout','POST'); await boot();})}>Выйти</button></aside>
      <main id="main" className="content" aria-busy={busy || loadingClub}>
        {messages}
        {token && invitation && <section className="join"><p className="eyebrow">Приглашение в клуб</p><h1>{invitation.club_name}</h1><p>Как вас будут знать в клубе? Имя можно выбрать независимо от профиля Telegram или VK.</p><form onSubmit={e => {e.preventDefault(); void action(async () => {const result = await request<{club_id: number}>(`/api/invitations/${token}/accept`,'POST',{club_name: clubName}); history.replaceState({},'', '/'); setInvitation(null); await boot(); setClubId(result.club_id); setNotice('Вы в клубе. Добро пожаловать!');});}}><label>Клубное имя<input value={clubName} onChange={e => setClubName(e.target.value)} minLength={2} maxLength={80} required autoComplete="nickname"/></label><button disabled={busy}>Присоединиться</button></form></section>}
        {screen === 'account' ? <><p className="eyebrow">Мой аккаунт</p><h1>{session.user.name}</h1><p className="lead">Два способа входа могут вести в один аккаунт. Привяжите второй здесь, пока вы вошли в первый.</p><section className="section"><h2>Способы входа</h2>{(['telegram','vk'] as Provider[]).map(p => <div className="account-row" key={p}><div><strong>{providerNames[p]}</strong><p>{session.identities.includes(p) ? 'Привязан к этому аккаунту' : 'Не привязан'}</p></div>{!session.identities.includes(p) && <button className="secondary" disabled={busy || !session.providers[p]} onClick={() => void action(async () => {const data = await request<{url: string}>(`/api/account/link/${p}`,'POST'); location.assign(data.url);})}>{session.providers[p] ? 'Привязать' : 'Пока недоступно'}</button>}</div>)}</section><section className="section"><h2>Другие устройства</h2><p>Если вы входили с чужого телефона, завершите остальные сеансы. Здесь вы останетесь в аккаунте.</p><button className="secondary" disabled={busy} onClick={() => void action(async () => {await request('/api/account/revoke-other-sessions','POST'); setNotice('На других устройствах выполнен выход.');})}>Выйти на других устройствах</button></section></> : screen === 'finance' && club ? <Finance key={club.id} club={club} request={request}/> : <>
          <p className="eyebrow">Мой клуб</p><h1>{club?.name ?? 'Здесь появится ваш клуб'}</h1>
          {!club ? <p className="lead">Откройте приглашение от владельца или управляющего клуба.</p> : <><p className="lead">В клубе вас знают как <strong>{club.club_name}</strong>.</p><p className="status-line">{statusNames[club.status] ?? club.status}{club.owner_membership_id === club.membership_id ? ' / Владелец клуба' : ''}</p>
            {club.status === 'excluded' ? <p>Чтобы вернуться, обратитесь к управляющему клуба. Старые записи сохраняются.</p> : <>
              {can('invitations.manage') && <section className="section"><div className="section-head"><div><h2>Пригласить своих</h2><p>Одна ссылка для нескольких участников. Действует 7 дней.</p></div><button disabled={busy} onClick={() => void action(async () => {const data = await request<{url: string}>(`/api/clubs/${club.id}/invitations`,'POST'); setNewInvite(data.url); setInvites(await request<Invite[]>(`/api/clubs/${club.id}/invitations`));})}>Создать ссылку</button></div>{newInvite && <div className="copy-link"><label>Новая ссылка<input readOnly value={newInvite} onFocus={e => e.target.select()}/></label><button className="secondary" onClick={() => void action(async () => {await navigator.clipboard.writeText(newInvite); setNotice('Ссылка скопирована. Отправьте её участникам клуба.');})}>Скопировать</button><p className="hint">Сохраните ссылку сейчас. После обновления страницы её текст не показывается.</p></div>}{invites.length > 0 && <details><summary>Действующие приглашения: {invites.length}</summary>{invites.map(i => <div className="account-row" key={i.id}><span>Ссылка №{i.id}<small>До {new Date(i.expires_at).toLocaleString('ru-RU')}</small></span><button className="quiet danger" disabled={busy} onClick={() => void action(async () => {await request(`/api/clubs/${club.id}/invitations/${i.id}`,'DELETE'); setInvites(old => old.filter(x => x.id !== i.id)); setNewInvite(''); setNotice('Приглашение отозвано.');})}>Отозвать</button></div>)}</details>}</section>}
              {loadingClub && <p role="status">Загружаем участников…</p>}
              {can('members.view') && !loadingClub && <section className="section"><h2>Участники</h2><p className="hint">Права каждого действуют только в этом клубе.</p><ul className="members">{members.map(m => <li key={m.id}><button className="member" onClick={() => pick(m)} disabled={busy}><span className="avatar" aria-hidden="true">{m.club_name.slice(0,1)}</span><span><strong>{m.club_name}</strong><small>{m.owner ? 'Владелец клуба' : statusNames[m.status]}{m.status === 'frozen' && m.frozen_until ? ` до ${new Date(m.frozen_until).toLocaleDateString('ru-RU')}` : ''}</small></span><span className="member-action">Открыть</span></button></li>)}</ul>{nextPage && <button className="secondary" disabled={busy} onClick={() => void action(async () => {const data = await request<{data: Member[]; next_page: number | null}>(`/api/clubs/${club.id}/members?page=${nextPage}`); setMembers(old => [...old,...data.data]); setNextPage(data.next_page);})}>Показать ещё</button>}</section>}
              {!can('members.view') && <section className="section"><h2>Вы в клубе</h2><p>Ваше членство активно. Расписание и взносы появятся на следующих этапах раннего доступа.</p></section>}
              {selected && <section className="section member-editor" aria-labelledby="member-heading"><div className="section-head"><h2 id="member-heading">{selected.club_name}</h2><button className="quiet" onClick={() => setSelected(null)}>Закрыть</button></div>
                {can('permissions.manage') && selected.status !== 'excluded' && <form onSubmit={e => {e.preventDefault(); void action(async () => {await request(`/api/clubs/${club.id}/members/${selected.id}/permissions`,'PUT',{permissions:rights}); await refreshClub(); setNotice('Права обновлены.');});}}><fieldset><legend>Что может делать участник</legend>{Object.entries(permissionNames).map(([key,label]) => <label className="checkbox" key={key}><input type="checkbox" checked={rights.includes(key)} onChange={e => setRights(old => e.target.checked ? [...old,key] : old.filter(p => p !== key))}/><span>{label}</span></label>)}</fieldset><p className="hint">Владение клубом само по себе не открывает казну. Это право назначается отдельно.</p><button disabled={busy}>Сохранить права</button></form>}
                {can('members.manage') && <form className="subsection" onSubmit={e => {e.preventDefault(); void action(async () => {await request(`/api/clubs/${club.id}/members/${selected.id}/status`,'PUT',{status,reason,frozen_until:status === 'frozen' && until ? new Date(until).toISOString() : null}); await refreshClub(); setNotice('Статус обновлён. История сохранена.');});}}><h3>Членство</h3><label>Статус<select value={status} onChange={e => setStatus(e.target.value)}><option value="active">В клубе</option><option value="frozen">На паузе</option><option value="excluded">Исключён</option></select></label>{status === 'frozen' && <label>До какого времени (необязательно)<input type="datetime-local" value={until} onChange={e => setUntil(e.target.value)}/></label>}<label>Причина<input value={reason} onChange={e => setReason(e.target.value)} required minLength={2} maxLength={500}/></label><button className="secondary" disabled={busy}>Изменить статус</button></form>}
                {can('ownership.transfer') && !selected.owner && selected.status === 'active' && <form className="subsection" onSubmit={e => {e.preventDefault(); if (!confirmTransfer) return; void action(async () => {await request(`/api/clubs/${club.id}/ownership`,'POST',{membership_id:selected.id}); await refreshClub(); setNotice('Владение передано. Ваши явно назначенные права сохранены.');});}}><h3>Передать владение</h3><p>Управление правами и владением перейдёт к {selected.club_name}.</p><label className="checkbox"><input type="checkbox" checked={confirmTransfer} onChange={e => setConfirmTransfer(e.target.checked)}/><span>Я подтверждаю передачу владения</span></label><button className="secondary" disabled={busy || !confirmTransfer}>Передать владение</button></form>}
              </section>}
            </>}
          </>}
        </>}
      </main>
    </div>}
  </>;
}

createRoot(document.getElementById('root')!).render(<App/>);

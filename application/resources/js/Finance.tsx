import { BillingSettings } from './BillingSettings';
import { major, minor, money } from './money';
import { useEffect, useRef, useState, type FormEvent } from 'react';

type Requester = <T>(path: string, method?: string, body?: unknown, headers?: Record<string, string>) => Promise<T>;
type Opening = { event_id: number; amount_minor: string; occurred_at: string } | null;
type Member = { id: number; club_name: string; status: string; balance_minor: string; opening: Opening };
type PersonalEvent = { id: number; type: string; reason: string; occurred_at: string; created_at: string; amount_minor: string; related_event_id: number | null };
type Own = { currency: string; scale: number; timezone: string; balance_minor: string; custody_minor: string; history: PersonalEvent[]; next_page: number | null; cash_history: PersonalEvent[]; cash_next_page: number | null };
type Summary = { currency: string; scale: number; timezone: string; local_date: string; total_minor: string; card_minor: string; custody_minor: string; debt_minor: string; advance_minor: string; card_opening: Opening; holders: { membership_id: number; club_name: string; amount_minor: string; opening: Opening }[] };
type JournalRow = Omit<PersonalEvent, 'amount_minor'> & { author: string; opening_value_minor: string | null; members: { club_name: string; amount_minor: string }[]; money: { location: string; club_name: string | null; amount_minor: string }[] };
type Target = { kind: 'member' | 'card' | 'custody'; member: number | null; name: string; opening: Opening };

const names: Record<string, string> = { opening_member: 'Начальный долг или переплата', opening_card: 'Начальная сумма на карте', opening_custody: 'Начальные деньги у ответственного', charge: 'Начислен взнос', card_receipt: 'Оплата на карту', cash_receipt: 'Оплата наличными', cash_transfer: 'Передача на карту', expense: 'Расход клуба', charge_reversal: 'Исправление начисления' };

function when(value: string, timezone: string): string {
  const date = new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
  return date.toLocaleString('ru-RU', { timeZone: timezone, dateStyle: 'short', timeStyle: 'short' });
}

export function Finance({ club, request }: { club: { id: number; name: string; capabilities: string[] }; request: Requester }) {
  const manage = club.capabilities.includes('finance.manage');
  const view = club.capabilities.includes('finance.view');
  const base = `/api/clubs/${club.id}/finance`;
  const [own, setOwn] = useState<Own | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [members, setMembers] = useState<Member[]>([]);
  const [memberPage, setMemberPage] = useState<number | null>(null);
  const [journal, setJournal] = useState<JournalRow[]>([]);
  const [journalPage, setJournalPage] = useState<number | null>(null);
  const [target, setTarget] = useState<Target | null>(null);
  const [amount, setAmount] = useState('');
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');
  const [cashMember, setCashMember] = useState('');
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const active = useRef(true);
  const pending = useRef<{ json: string; key: string } | null>(null);
  const formRef = useRef<HTMLElement>(null);

  async function load() {
    const personal = await request<Own>(`${base}/me`);
    if (!view) { if (active.current) setOwn(personal); return; }
    const [totals, people, events] = await Promise.all([
      request<Summary>(`${base}/summary`), request<{ data: Member[]; next_page: number | null }>(`${base}/members`), request<{ data: JournalRow[]; next_page: number | null }>(`${base}/journal`),
    ]);
    if (!active.current) return;
    setOwn(personal); setSummary(totals); setMembers(people.data); setMemberPage(people.next_page); setJournal(events.data); setJournalPage(events.next_page);
  }

  useEffect(() => {
    active.current = true;
    void load().catch(() => { if (active.current) setError('Не удалось загрузить суммы. Проверьте соединение и попробуйте ещё раз.'); }).finally(() => { if (active.current) setLoading(false); });
    return () => { active.current = false; };
  }, [club.id, view]);

  async function action(work: () => Promise<void>) {
    if (!navigator.onLine) { setError('Нет интернета. Подключитесь к сети и попробуйте снова.'); return; }
    setBusy(true); setError('');
    try { await work(); } catch (e) { if (active.current) setError(e instanceof TypeError ? 'Связь прервалась. Сохранение могло завершиться. Проверьте результат или повторите тот же запрос.' : e instanceof Error ? e.message : 'Не удалось выполнить действие.'); }
    finally { if (active.current) setBusy(false); }
  }

  function edit(next: Target) {
    if (!summary) return;
    setTarget(next); setAmount(next.opening ? major(next.opening.amount_minor, summary.scale).replace('.', ',') : '');
    const originalDate = next.opening ? new Date(next.opening.occurred_at.includes('T') ? next.opening.occurred_at : next.opening.occurred_at.replace(' ', 'T') + 'Z') : null;
    setDate(originalDate ? new Intl.DateTimeFormat('en-CA', { timeZone: summary.timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(originalDate) : summary.local_date);
    setReason(''); setError(''); setNotice(''); pending.current = null;
    requestAnimationFrame(() => { formRef.current?.scrollIntoView({ behavior: 'instant', block: 'start' }); formRef.current?.focus(); });
  }

  function save(event: FormEvent) {
    event.preventDefault(); if (!target || !summary) return;
    void action(async () => {
      const body = { target: target.kind, membership_id: target.member, amount_minor: minor(amount, summary.scale), currency: summary.currency, date, reason: reason.trim(), previous_event_id: target.opening?.event_id ?? null };
      const json = JSON.stringify(body);
      if (pending.current?.json !== json) pending.current = { json, key: crypto.randomUUID() };
      await request(`${base}/opening`, 'POST', body, { 'Idempotency-Key': pending.current.key });
      if (!active.current) return;
      setTarget(null); pending.current = null; setNotice('Начальные данные сохранены. Прежние записи остались в истории.');
      try { await load(); } catch { setError('Данные сохранены, но экран не обновился. Нажмите «Обновить суммы».'); }
    });
  }

  const fmt = (value: string) => money(value, own?.currency ?? 'RUB', own?.scale ?? 2);
  const timezone = own?.timezone ?? 'UTC';
  const refresh = () => void action(async () => { await load(); setTarget(null); pending.current = null; setNotice('Суммы обновлены. Если сохранение прерывалось, сверьте нужную запись в истории.'); });

  return <>
    <p className="eyebrow">{club.name}</p><h1>{view ? 'Деньги и взносы' : 'Мои взносы'}</h1>
    <p className="lead">{view ? 'Отдельно — деньги клуба, долги и переплаты участников.' : 'Здесь видны ваш долг, переплата и история изменений.'}</p>
    {error && !target && <div className="feedback error" role="alert">{error}</div>}
    {notice && <div className="feedback" role="status">{notice}</div>}
    <button className="secondary finance-refresh" disabled={busy || loading} onClick={refresh}>Обновить суммы</button>
    {loading ? <p role="status">Загружаем суммы…</p> : !own ? <p>Пока не удалось показать данные. Попробуйте обновить суммы.</p> : <>
      {!view && <section className="section"><h2>Мой взнос</h2><p className="finance-number">{BigInt(own.balance_minor) < 0n ? `Долг ${fmt((-BigInt(own.balance_minor)).toString())}` : BigInt(own.balance_minor) > 0n ? `Переплата ${fmt(own.balance_minor)}` : 'Долга нет'}</p>
        {BigInt(own.balance_minor) > 0n && <p className="hint">Переплата покроет следующие взносы.</p>}
        {BigInt(own.custody_minor) !== 0n && <p>У вас деньги клуба к передаче: <strong>{fmt(own.custody_minor)}</strong>.</p>}
      </section>}
      {summary && <>
        <section className="section"><h2>Деньги клуба</h2><div className="finance-cards">
          <div className="finance-card"><span>Всего у клуба</span><strong>{fmt(summary.total_minor)}</strong><small>Карта и деньги у ответственных вместе.</small></div>
          <div className="finance-card"><span>На клубной карте</span><strong>{fmt(summary.card_minor)}</strong>{manage && <button className="secondary" disabled={busy} onClick={() => edit({ kind: 'card', member: null, name: 'Клубная карта', opening: summary.card_opening })}>{summary.card_opening ? 'Исправить начальную сумму' : 'Внести начальную сумму'}</button>}</div>
          <div className="finance-card"><span>У ответственных</span><strong>{fmt(summary.custody_minor)}</strong><small>Эти деньги еще нужно передать клубу.</small></div>
          <div className="finance-card"><span>Долги участников</span><strong>{fmt(summary.debt_minor)}</strong><small>Пока не оплачены. В деньги клуба не включены.</small></div>
          <div className="finance-card"><span>Переплаты участников</span><strong>{fmt(summary.advance_minor)}</strong></div>
        </div></section>
        <section className="section"><h2>У кого деньги клуба</h2>{summary.holders.length ? <ul className="finance-list">{summary.holders.map(h => <li key={h.membership_id}><strong>{h.club_name}</strong><p>{fmt(h.amount_minor)}</p>{manage && <button className="secondary" disabled={busy} onClick={() => edit({ kind: 'custody', member: h.membership_id, name: h.club_name, opening: h.opening })}>Начальная сумма</button>}</li>)}</ul> : <p className="hint">Деньги у ответственных пока не внесены.</p>}
          {manage && <form className="finance-add" onSubmit={e => { e.preventDefault(); const m = members.find(m => m.id === Number(cashMember)); if (m) edit({ kind: 'custody', member: m.id, name: m.club_name, opening: summary.holders.find(h => h.membership_id === m.id)?.opening ?? null }); }}><label>Кто хранит начальные деньги клуба<select value={cashMember} required onChange={e => setCashMember(e.target.value)}><option value="">Выберите участника</option>{members.map(m => <option key={m.id} value={m.id}>{m.club_name}</option>)}</select></label><button className="secondary" disabled={busy || !cashMember}>Указать начальную сумму</button></form>}
        </section>
        <section className="section"><h2>Взносы участников</h2><p className="hint">При переносе укажите, сколько человек уже должен или сколько внес заранее. Это не новая оплата.</p><ul className="finance-list">{members.map(m => <li key={m.id}><strong>{m.club_name}</strong><p>{BigInt(m.balance_minor) < 0n ? `Долг ${fmt((-BigInt(m.balance_minor)).toString())}` : BigInt(m.balance_minor) > 0n ? `Переплата ${fmt(m.balance_minor)}` : 'Долга нет'}</p>{manage && <button className="secondary" disabled={busy} onClick={() => edit({ kind: 'member', member: m.id, name: m.club_name, opening: m.opening })}>{m.opening ? 'Исправить начальные данные' : 'Внести начальные данные'}</button>}</li>)}</ul>
          {memberPage && <button className="secondary" disabled={busy} onClick={() => void action(async () => { const data = await request<{ data: Member[]; next_page: number | null }>(`${base}/members?page=${memberPage}`); setMembers(old => [...old, ...data.data]); setMemberPage(data.next_page); })}>Еще участники</button>}
        </section>
      </>}
      {view && <BillingSettings clubId={club.id} manage={manage} request={request}/>}
      {target && summary && <section ref={formRef} tabIndex={-1} className="section finance-editor" aria-labelledby="opening-title"><h2 id="opening-title">{target.opening ? 'Исправить начальные данные' : 'Начальные данные'}: {target.name}</h2><p className="hint">{target.kind === 'member' ? 'Долг вводите со знаком минус: −2500. Переплату — без минуса: 1000. Ноль тоже можно сохранить.' : 'Укажите сумму к началу учета. Это не новое поступление.'}</p>{target.opening && <p>Ранее указано: <strong>{fmt(target.opening.amount_minor)}</strong>. Исправление сохранится отдельной записью.</p>}
        <form onSubmit={save}>{error && <div className="feedback error" role="alert">{error}<button type="button" className="quiet" disabled={busy} onClick={refresh}>Проверить результат</button></div>}<label>Сумма, {summary.currency}<input name="amount" type="text" inputMode={target.kind === 'member' ? 'text' : 'decimal'} value={amount} onChange={e => setAmount(e.target.value)} required disabled={busy} autoComplete="off" maxLength={30}/></label><label>На какую дату<input type="date" value={date} onChange={e => setDate(e.target.value)} required disabled={busy}/></label><label>{target.opening ? 'Почему исправляем' : 'Комментарий к переносу'}<input value={reason} onChange={e => setReason(e.target.value)} minLength={2} maxLength={500} required disabled={busy}/></label><p className="hint">Сохранится только изменение начальной суммы. Поздние оплаты и начисления останутся.</p><button disabled={busy}>{busy ? 'Сохраняем…' : 'Сохранить начальные данные'}</button><button type="button" className="quiet" disabled={busy} onClick={() => setTarget(null)}>Закрыть</button></form>
      </section>}
      <section className="section"><h2>{view ? 'История клуба' : 'Моя история'}</h2><p className="hint">Начальные данные выделены отдельно. Исправления сохраняются рядом с исходными записями.</p>
        {view ? <><ol className="finance-history">{journal.map(e => <li key={e.id}><strong>{names[e.type] ?? 'Операция'}{e.related_event_id ? ' · исправление' : ''}</strong><p>{e.reason}</p>{e.opening_value_minor !== null && <p>Начальная сумма: {fmt(e.opening_value_minor)}</p>}{e.members.map((m, i) => <p key={`m${i}`}>{m.club_name}: {fmt(m.amount_minor)}</p>)}{e.money.map((m, i) => <p key={`c${i}`}>{m.location === 'card' ? 'Карта' : `У ${m.club_name}`}: {fmt(m.amount_minor)}</p>)}<small>На дату: {when(e.occurred_at, timezone)}<br/>Внесено: {when(e.created_at, timezone)} · {e.author}<br/>Запись №{e.id}{e.related_event_id ? `, исправляет №${e.related_event_id}` : ''}</small></li>)}</ol>{!journal.length && <p>Записей пока нет.</p>}{journalPage && <button className="secondary" disabled={busy} onClick={() => void action(async () => { const data = await request<{ data: JournalRow[]; next_page: number | null }>(`${base}/journal?page=${journalPage}`); setJournal(old => [...old, ...data.data]); setJournalPage(data.next_page); })}>Еще записи</button>}</>
        : <><ol className="finance-history">{own.history.map(e => <li key={e.id}><strong>{names[e.type] ?? 'Изменение взноса'}</strong><p>{fmt(e.amount_minor)}</p><p>{e.reason}</p><small>На дату: {when(e.occurred_at, timezone)}<br/>Внесено: {when(e.created_at, timezone)} · №{e.id}{e.related_event_id ? `, исправляет №${e.related_event_id}` : ''}</small></li>)}</ol>{!own.history.length && <p>Начислений и оплат пока нет.</p>}{own.next_page && <button className="secondary" disabled={busy} onClick={() => void action(async () => { const data = await request<Own>(`${base}/me?page=${own.next_page}`); setOwn(old => old ? { ...data, history: [...old.history, ...data.history] } : data); })}>Еще записи</button>}</>}
      </section>
      {!view && own.cash_history.length > 0 && <section className="section"><h2>Деньги клуба у меня</h2><p className="hint">Здесь только суммы, за которые вы отвечаете.</p><ol className="finance-history">{own.cash_history.map(e => <li key={e.id}><strong>{names[e.type] ?? 'Изменение суммы'}</strong><p>{fmt(e.amount_minor)}</p><p>{e.reason}</p><small>{when(e.occurred_at, timezone)} · №{e.id}</small></li>)}</ol>{own.cash_next_page && <button className="secondary" disabled={busy} onClick={() => void action(async () => { const data = await request<Own>(`${base}/me?cash_page=${own.cash_next_page}`); setOwn(old => old ? { ...old, custody_minor: data.custody_minor, cash_history: [...old.cash_history, ...data.cash_history], cash_next_page: data.cash_next_page } : data); })}>Еще движения денег</button>}</section>}
      <p className="hint finance-footnote">Оплаты, тренировки и возвраты появятся на следующих этапах. Здесь сейчас переносим начальные данные.</p>
    </>}
  </>;
}

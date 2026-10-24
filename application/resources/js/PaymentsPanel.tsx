import { useEffect, useRef, useState, type FormEvent } from 'react';
import { major, minor, money } from './money';

type Requester = <T>(path: string, method?: string, body?: unknown, headers?: Record<string,string>) => Promise<T>;
type Club = {id:number; capabilities:string[]; membership_id?:number; status?:string};
type Person = {id:number; club_name:string;opening_refund?:{event_id:number;amount_minor:string}|null};
type Options = {currency:string;scale:number;local_date:string;local_datetime:string;timezone:string;members:Person[]|null;receivers:Person[];next_page:number|null};
type Claim = {id:number;membership_id:number;custodian_membership_id:number|null;club_name:string;receiver_name:string|null;amount_minor:string;method:string;paid_on:string;status:string;receipt_id:number|null;comment:string|null;review_reason:string|null;has_attachment:boolean};
type Receipt = {id:number;membership_id:number|null;club_name:string|null;receiver_name:string|null;amount_minor:string;method:string;paid_on:string;version:number;claim_id:number|null;event_id:number;remaining_minor?:string;cancelled_minor?:string;refunded_minor?:string};
type Expense = {id:number;template_id:number;title:string;due_on:string;amount_minor:string;event_id:number|null};
type Template = {id:number;title:string;amount_minor:string;day_of_month:number;version:number;active:boolean};
type Reconciliation = {id:number;as_of:string;comment:string;actual_minor:string;calculated_minor:string;difference_minor:string;current_calculated_minor:string;current_difference_minor:string};
type Page<T> = {data:T[];next_page:number|null};
type Form = {kind:'claim'|'receipt'|'review'|'identify'|'refundOpening'|'cheque'|'bankCancel'|'refund'|'transfer'|'expense'|'template'|'generate'|'reconcile'|'adjust';id?:number;version?:number;claim?:Claim;reconciliation?:Reconciliation;occurrence?:number;title:string};
const statuses:Record<string,string> = {pending:'Ожидает проверки',confirmed:'Подтверждена',rejected:'Отклонена'};

export function PaymentsPanel({club,request,onChange}:{club:Club;request:Requester;onChange:()=>Promise<void>}) {
  const base = `/api/clubs/${club.id}/finance`;
  const manage = club.capabilities.includes('finance.manage');
  const view = club.capabilities.includes('finance.view');
  const receive = club.capabilities.includes('cash.receive');
  const [options,setOptions] = useState<Options|null>(null);
  const [tab,setTab] = useState<'own'|'inbox'|'receipts'|'expenses'|'reconcile'>('own');
  const [claims,setClaims] = useState<Claim[]>([]);
  const [receipts,setReceipts] = useState<Receipt[]>([]);
  const [expenses,setExpenses] = useState<Expense[]>([]);
  const [templates,setTemplates] = useState<Template[]>([]);
  const [reconciliations,setReconciliations] = useState<Reconciliation[]>([]);
  const [next,setNext] = useState<number|null>(null);
  const [form,setForm] = useState<Form|null>(null);
  const [file,setFile]=useState<File|null>(null);
  const [amount,setAmount] = useState(''); const [date,setDate] = useState('');
  const [reason,setReason] = useState(''); const [member,setMember] = useState('');
  const [method,setMethod] = useState('card'); const [holder,setHolder] = useState('');
  const [decision,setDecision] = useState('confirm'); const [receiptId,setReceiptId] = useState('');
  const [ack,setAck] = useState(false); const [confirm,setConfirm] = useState(false);
  const [title,setTitle] = useState(''); const [day,setDay] = useState('10'); const [enabled,setEnabled] = useState(true);
  const [month,setMonth] = useState(''); const [instant,setInstant] = useState('');
  const [busy,setBusy] = useState(false); const [loading,setLoading] = useState(true);
  const [error,setError] = useState(''); const [notice,setNotice] = useState('');
  const alive = useRef(true); const sequence = useRef(0);
  const pending = useRef(new Map<string,{json:string;key:string}>()); const editor = useRef<HTMLElement>(null);
  const fmt = (s:string) => money(s,options?.currency??'RUB',options?.scale??2);

  async function load(page=1,append=false) {
    const serial=++sequence.current;
    let path='claims'; if(tab==='inbox')path+='?scope=inbox';
    if(tab==='receipts')path='receipts'; if(tab==='expenses')path='expenses'; if(tab==='reconcile')path='reconciliations';
    path+=path.includes('?')?`&page=${page}`:`?page=${page}`;
    const data=await request<Page<Claim|Receipt|Expense|Reconciliation>&{templates?:Template[]}>(`${base}/${path}`);
    if(!alive.current||serial!==sequence.current)return;
    if(tab==='own'||tab==='inbox')setClaims(old=>append?[...old,...data.data as Claim[]]:data.data as Claim[]);
    if(tab==='receipts')setReceipts(old=>append?[...old,...data.data as Receipt[]]:data.data as Receipt[]);
    if(tab==='expenses'){setExpenses(old=>append?[...old,...data.data as Expense[]]:data.data as Expense[]);setTemplates(data.templates??[]);}
    if(tab==='reconcile')setReconciliations(old=>append?[...old,...data.data as Reconciliation[]]:data.data as Reconciliation[]);
    setNext(data.next_page);
  }
  useEffect(()=>{alive.current=true; request<Options>(`${base}/payment-options`).then(d=>{if(alive.current)setOptions(d);}).catch(e=>{if(alive.current)setError(e.message);});return()=>{alive.current=false;};},[base]);
  useEffect(()=>{setLoading(true);setError('');setForm(null);void load().catch(e=>{if(alive.current)setError(e.message);}).finally(()=>{if(alive.current)setLoading(false);});},[tab,base]);
  useEffect(()=>{if(form)editor.current?.focus();},[form]);

  function open(value:Form,seed?:{amount?:string;date?:string;title?:string;day?:number;active?:boolean}) {
    setFile(null);setForm(value);setAmount(seed?.amount?major(seed.amount,options?.scale??2):'');setDate(seed?.date??options?.local_date??'');setReason('');setMember('');setMethod(receive&&!manage?'cash':'card');setHolder('');setDecision(value.claim?.method==='cash'&&!(receive&&value.claim.custodian_membership_id===club.membership_id)?'link':'confirm');setReceiptId('');setAck(false);setConfirm(false);setTitle(seed?.title??'');setDay(String(seed?.day??10));setEnabled(seed?.active??true);setMonth(options?.local_date.slice(0,7)??'');
    setInstant(options?.local_datetime??((options?.local_date??'')+'T00:00'));setError('');setNotice('');
  }
  async function mutation(path:string,body:unknown,verb='POST') {
    const json=JSON.stringify(body);const requestId=path+"|"+json;let token=pending.current.get(requestId);
    if(!token||token.json!==json){token={json,key:crypto.randomUUID()};pending.current.set(requestId,token);}
    const result=await request(`${base}/${path}`,verb,body,{'Idempotency-Key':token.key});pending.current.delete(requestId);return result;
  }
  async function refresh(){await Promise.all([load(),onChange()]);}
  async function save(event:FormEvent) {
    event.preventDefault();if(!form||!options||!confirm)return;setBusy(true);setError('');setNotice('');
    try{
      const fact={currency:options.currency,amount_minor:['review','identify','template','generate','adjust','cheque'].includes(form.kind)?'0':minor(amount,options.scale),date,reason};
      if(form.kind==='cheque'){
        if(!file)throw new Error('Выберите файл чека.');
        if(file.size>10*1024*1024)throw new Error('Размер чека — не больше 10 МБ.');
        const digest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await file.arrayBuffer()))).map(x=>x.toString(16).padStart(2,'0')).join('');
        const path=`claims/${form.id}/cheque`;const identity=path+'|'+digest;let token=pending.current.get(identity);
        if(!token){token={json:digest,key:crypto.randomUUID()};pending.current.set(identity,token);}
        const upload=new FormData();upload.append('file',file);
        await request(`${base}/${path}`,'POST',upload,{'Idempotency-Key':token.key});pending.current.delete(identity);
      }
      if(form.kind==='bankCancel')await mutation(`receipts/${form.id}/bank-cancel`,{...fact,version:form.version});
      if(form.kind==='claim')await mutation('claims',{...fact,method,custodian_membership_id:method==='cash'?Number(holder):null});
      if(form.kind==='receipt')await mutation('receipts',{...fact,method,membership_id:member?Number(member):null,duplicates_acknowledged:ack});
      if(form.kind==='review')await mutation(`claims/${form.id}/review`,{decision,reason,receipt_id:receiptId?Number(receiptId):null,duplicates_acknowledged:ack});
      if(form.kind==='identify')await mutation(`receipts/${form.id}/identify`,{membership_id:Number(member),version:form.version,reason});
      if(form.kind==='refundOpening')await mutation('opening-refunds',{...fact,membership_id:Number(member),opening_event_id:people.find(p=>p.id===Number(member))?.opening_refund?.event_id});
      if(form.kind==='refund')await mutation(`receipts/${form.id}/refund`,{...fact,version:form.version});
      if(form.kind==='transfer')await mutation('transfers',{...fact,custodian_membership_id:Number(holder)});
      if(form.kind==='expense')await mutation(form.occurrence?`expenses/${form.occurrence}/pay`:'expenses',fact);
      if(form.kind==='template')await mutation(form.id?`expense-templates/${form.id}`:'expense-templates',{title,amount_minor:minor(amount,options.scale),currency:options.currency,day_of_month:Number(day),active:enabled,version:form.version??1},form.id?'PUT':'POST');
      if(form.kind==='generate')await mutation(`expense-templates/${form.id}/generate`,{month});
      if(form.kind==='reconcile')await mutation('reconciliations',{actual_minor:minor(amount,options.scale),as_of:instant,comment:reason});
      if(form.kind==='adjust')await mutation(`reconciliations/${form.id}/adjust`,{expected_calculated_minor:form.reconciliation?.current_calculated_minor,currency:options.currency,reason});
      if(!alive.current)return;setForm(null);setNotice('Сохранено.');
      try{await refresh();}catch{setError('Операция сохранена, но экран не обновился. Нажмите «Обновить операции».');}
    }catch(e){if(alive.current)setError(e instanceof Error?e.message:'Не удалось сохранить. Проверьте связь и повторите.');}
    finally{if(alive.current)setBusy(false);}
  }
  const needAmount=form&&['claim','receipt','refundOpening','refund','bankCancel','transfer','expense','template','reconcile'].includes(form.kind);
  const needDate=form&&['claim','receipt','refundOpening','refund','bankCancel','transfer','expense'].includes(form.kind);
  const needReason=form&& !['template','generate','cheque'].includes(form.kind);
  const people=options?.members??[];
  const choosePerson=<label>Участник<select required value={member} onChange={e=>setMember(e.target.value)} disabled={busy}><option value="">Выберите участника</option>{people.map(m=><option key={m.id} value={m.id}>{m.club_name}</option>)}</select></label>;
  const chooseHolder=<label>Кто принял деньги<select required value={holder} onChange={e=>setHolder(e.target.value)} disabled={busy}><option value="">Выберите получателя</option>{(form?.kind==='transfer'?people:options?.receivers??[]).map(m=><option key={m.id} value={m.id}>{m.club_name}</option>)}</select></label>;

  return <section className="section payments-panel"><h2>Оплаты и расходы</h2><p className="hint">Асгард сохраняет то, что уже произошло. Деньги переводите привычным способом, отдельно от приложения.</p>
    <div className="actions"><button disabled={busy||!options} onClick={()=>open({kind:'claim',title:'Сообщить об оплате'})}>Я оплатил</button>{(manage||receive)&&<button className="secondary" disabled={busy||!options} onClick={()=>open({kind:'receipt',title:receive&&!manage?'Принять наличные':'Внести поступление'})}>{receive&&!manage?'Принять наличные':'Внести поступление'}</button>}{manage&&<><button className="secondary" disabled={busy||!options} onClick={()=>open({kind:'transfer',title:'Подтвердить передачу на карту'})}>Передача на карту</button><button className="secondary" disabled={busy||!options} onClick={()=>open({kind:'expense',title:'Оплаченный расход'})}>Внести расход</button><button className="quiet" disabled={busy||!options} onClick={()=>open({kind:'refundOpening',title:'Вернуть начальную переплату'})}>Возврат начальной переплаты</button></>}</div>
    <div className="payment-tabs" aria-label="Разделы оплат"><button className={tab==='own'?'secondary':'quiet'} disabled={busy} onClick={()=>setTab('own')}>Мои заявки</button>{(view||receive)&&<><button className={tab==='inbox'?'secondary':'quiet'} disabled={busy} onClick={()=>setTab('inbox')}>Проверить заявки</button><button className={tab==='receipts'?'secondary':'quiet'} disabled={busy} onClick={()=>setTab('receipts')}>{view?'Поступления':'Принятые мной'}</button></>}{view&&<><button className={tab==='expenses'?'secondary':'quiet'} disabled={busy} onClick={()=>setTab('expenses')}>Расходы</button><button className={tab==='reconcile'?'secondary':'quiet'} disabled={busy} onClick={()=>setTab('reconcile')}>Сверка карты</button></>}</div>
    {error&&<div className="feedback error" role="alert">{error}</div>}{notice&&<div className="feedback" role="status">{notice}</div>}
    <button className="quiet" disabled={busy||loading} onClick={()=>{setBusy(true);void refresh().catch(e=>setError(e.message)).finally(()=>setBusy(false));}}>Обновить операции</button>
    {loading?<p role="status">Загружаем…</p>:<>
      {(tab==='own'||tab==='inbox')&&<ul className="finance-list">{!claims.length&&<li>Заявок пока нет.</li>}{claims.map(c=><li key={c.id}><strong>{c.club_name} · {fmt(c.amount_minor)}</strong><p>{c.paid_on} · {c.method==='cash'?`Наличные: ${c.receiver_name}`:'На карту'} · {statuses[c.status]}</p>{c.comment&&<p>{c.comment}</p>}{c.review_reason&&<p>{c.review_reason}</p>}{c.has_attachment&&(tab==='own'||view)&&<p><a href={`${base}/claims/${c.id}/cheque`}>Скачать чек</a></p>}{tab==='own'&&!c.has_attachment&&<button className="quiet" disabled={busy} onClick={()=>open({kind:'cheque',id:c.id,title:'Прикрепить чек'})}>Прикрепить чек</button>}{c.receipt_id&&<small>Поступление №{c.receipt_id}</small>}{tab==='inbox'&&c.status==='pending'&&((c.method==='card'&&manage)||(c.method==='cash'&&receive&&c.custodian_membership_id===club.membership_id)||manage)&&<button className="secondary" disabled={busy} onClick={()=>open({kind:'review',id:c.id,claim:c,title:`Проверить оплату: ${c.club_name}`})}>Проверить</button>}</li>)}</ul>}
      {tab==='receipts'&&<ul className="finance-list">{!receipts.length&&<li>Подтверждённых поступлений пока нет.</li>}{receipts.map(r=><li key={r.id}><strong>{r.club_name??'Отправитель пока неизвестен'} · {fmt(r.amount_minor)}</strong><p>{r.paid_on} · {r.method==='cash'?`Принял ${r.receiver_name}`:'На карту'} · №{r.id}</p>{r.claim_id&&<small>По заявке №{r.claim_id}</small>}{view&&r.cancelled_minor!=='0'&&<p>Банк отменил: {fmt(r.cancelled_minor??'0')}</p>}{view&&r.refunded_minor!=='0'&&<p>Вернули переплату: {fmt(r.refunded_minor??'0')}</p>}{manage&&BigInt(r.remaining_minor??'0')>0n&&<div className="actions"><button className="secondary" disabled={busy} onClick={()=>open({kind:'identify',id:r.id,version:r.version,title:r.club_name?'Исправить плательщика':'Указать плательщика'})}>{r.club_name?'Исправить плательщика':'Указать плательщика'}</button>{r.club_name&&<button className="quiet" disabled={busy} onClick={()=>open({kind:'refund',id:r.id,version:r.version,title:`Вернуть переплату: ${r.club_name}`})}>Вернуть переплату</button>}{r.method==='card'&&<button className="quiet" disabled={busy} onClick={()=>open({kind:'bankCancel',id:r.id,version:r.version,title:'Банк отменил поступление'})}>Банковская отмена</button>}</div>}</li>)}</ul>}
      {tab==='expenses'&&<>{manage&&<button className="secondary" disabled={busy} onClick={()=>open({kind:'template',title:'Регулярный расход'})}>Добавить регулярный расход</button>}<p className="hint">Ожидаемые расходы не уменьшают деньги клуба. Подтверждайте оплату после фактического перевода.</p><ul className="finance-list">{templates.map(t=><li key={t.id}><strong>{t.title} · {fmt(t.amount_minor)}</strong><p>Каждый месяц, {t.day_of_month}-го числа{!t.active?' · выключен':''}</p>{manage&&<div className="actions"><button className="secondary" disabled={busy} onClick={()=>open({kind:'template',id:t.id,version:t.version,title:'Изменить будущий шаблон'},{amount:t.amount_minor,title:t.title,day:t.day_of_month,active:t.active})}>Изменить</button>{t.active&&<button className="quiet" disabled={busy} onClick={()=>open({kind:'generate',id:t.id,title:`Ожидать оплату: ${t.title}`})}>Добавить месяц</button>}</div>}</li>)}</ul><ul className="finance-list">{expenses.map(e=><li key={e.id}><strong>{e.title} · {fmt(e.amount_minor)}</strong><p>{e.due_on} · {e.event_id?'Оплачен':'Ожидается оплата'}</p>{manage&&!e.event_id&&<button className="secondary" disabled={busy} onClick={()=>open({kind:'expense',occurrence:e.id,title:`Подтвердить расход: ${e.title}`},{amount:e.amount_minor})}>Подтвердить оплату</button>}</li>)}</ul></>}
      {tab==='reconcile'&&<>{manage&&<button className="secondary" disabled={busy} onClick={()=>open({kind:'reconcile',title:'Сверить клубную карту'})}>Указать сумму по банку</button>}<p className="hint">Сравниваем только клубную карту на выбранный момент. Если забыли внести оплату или расход, внесите их обычным действием. Исправление ниже — только для неизвестной причины.</p><ul className="finance-list">{reconciliations.map(r=><li key={r.id}><strong>{r.comment}</strong><p>{r.as_of} UTC</p><p>По банку: {fmt(r.actual_minor)}<br/>По записям при сверке: {fmt(r.calculated_minor)}<br/>Разница при сверке: {fmt(r.difference_minor)}</p><p>С учётом новых записей: {fmt(r.current_calculated_minor)}<br/>Осталась разница: {fmt(r.current_difference_minor)}</p>{manage&&r.current_difference_minor!=='0'&&<button className="quiet" disabled={busy} onClick={()=>open({kind:'adjust',id:r.id,reconciliation:r,title:'Исправить необъяснимое расхождение'})}>Причина неизвестна</button>}</li>)}</ul></>}
      {next&&<button className="secondary" disabled={busy} onClick={()=>{setBusy(true);void load(next,true).catch(e=>setError(e.message)).finally(()=>setBusy(false));}}>Показать ещё</button>}
    </>}
    {form&&options&&<section className="finance-editor" ref={editor} tabIndex={-1} aria-labelledby="payment-form-title"><h3 id="payment-form-title">{form.title}</h3><form onSubmit={save}>
      {form.kind==='cheque'&&<><p className="hint">Один JPG, PNG или PDF до 10 МБ. Чек увидите только вы и казначей. После сохранения замена файла не предусмотрена.</p><label>Файл чека<input type="file" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" required disabled={busy} onChange={e=>setFile(e.target.files?.[0]??null)}/></label></>}
      {form.kind==='bankCancel'&&<p className="hint">Только для реально отменённого банком поступления. Деньги уменьшатся, у текущего плательщика может снова появиться долг. Для возврата переплаты выберите отдельное действие.</p>}
      {form.claim&&<p>{fmt(form.claim.amount_minor)} · {form.claim.paid_on} · {form.claim.method==='cash'?`Наличные: ${form.claim.receiver_name}`:'На карту'}</p>}
      {form.kind==='review'&&<><label>Решение<select value={decision} onChange={e=>setDecision(e.target.value)} disabled={busy}>{(form.claim?.method==='card'||(receive&&form.claim?.custodian_membership_id===club.membership_id))&&<><option value="confirm">Подтвердить поступление</option><option value="reject">Отклонить заявку</option></>}{manage&&<option value="link">Это уже внесённое поступление</option>}</select></label>{decision==='link'&&<label>Номер поступления из списка<input type="number" inputMode="numeric" min="1" required value={receiptId} onChange={e=>setReceiptId(e.target.value)} disabled={busy}/></label>}</>}
      {form.kind==='receipt'&&<><label>Кто оплатил<select value={member} onChange={e=>setMember(e.target.value)} required={method==='cash'} disabled={busy}><option value="">Пока не знаю</option>{people.map(m=><option key={m.id} value={m.id}>{m.club_name}</option>)}</select></label><label>Как поступили деньги<select value={method} onChange={e=>setMethod(e.target.value)} disabled={busy}>{manage&&<option value="card">На клубную карту</option>}{receive&&<option value="cash">Я принял наличные</option>}</select></label></>}
      {form.kind==='claim'&&<><label>Как оплатили<select value={method} onChange={e=>setMethod(e.target.value)} disabled={busy}><option value="card">На клубную карту</option><option value="cash">Передал наличные</option></select></label>{method==='cash'&&chooseHolder}</>}
      {form.kind==='refundOpening'&&<><label>Кому возвращаем начальную переплату<select required value={member} onChange={e=>setMember(e.target.value)} disabled={busy}><option value="">Выберите участника</option>{people.filter(p=>p.opening_refund).map(p=><option key={p.id} value={p.id}>{p.club_name}</option>)}</select></label><p className="hint">Возврат ограничен текущей переплатой и ещё не возвращённым начальным авансом.</p></>}
      {form.kind==='identify'&&<>{choosePerson}<p className="hint">Перенесётся оставшаяся сумма этого платежа. Деньги клуба останутся прежними, исправление сохранится в истории.</p></>}
      {form.kind==='transfer'&&<>{chooseHolder}<p className="hint">Подтверждайте после поступления на клубную карту. Это не новая оплата участника.</p></>}
      {form.kind==='template'&&<><label>Название расхода<input value={title} onChange={e=>setTitle(e.target.value)} minLength={2} maxLength={150} required disabled={busy}/></label><label>День месяца<input type="number" inputMode="numeric" min="1" max="31" required value={day} onChange={e=>setDay(e.target.value)} disabled={busy}/></label><label className="check-row"><input type="checkbox" checked={enabled} onChange={e=>setEnabled(e.target.checked)} disabled={busy}/>Шаблон включён</label><p className="hint">В коротком месяце используем последний день. Уже добавленные месяцы останутся прежними.</p></>}
      {form.kind==='generate'&&<label>Месяц<input type="month" value={month} required onChange={e=>setMonth(e.target.value)} disabled={busy}/></label>}
      {needAmount&&<label>{form.kind==='reconcile'?'Фактически на карте':'Сумма'}, {options.currency}<input type="text" inputMode="decimal" value={amount} onChange={e=>setAmount(e.target.value)} required autoComplete="off" maxLength={30} disabled={busy}/></label>}
      {needDate&&<label>{form.kind==='bankCancel'?'Дата банковской отмены':['refund','refundOpening'].includes(form.kind)?'Дата возврата':form.kind==='transfer'?'Дата поступления на карту':'Дата фактической оплаты'}<input type="date" value={date} onChange={e=>setDate(e.target.value)} max={options.local_date} required disabled={busy}/></label>}
      {form.kind==='reconcile'&&<label>На какой момент, время клуба<input type="datetime-local" value={instant} onChange={e=>setInstant(e.target.value)} required disabled={busy}/></label>}
      {needReason&&<label>{form.kind==='claim'?'Комментарий (необязательно)':'Комментарий или причина'}<textarea value={reason} onChange={e=>setReason(e.target.value)} required={form.kind!=='claim'} minLength={form.kind==='claim'?undefined:2} maxLength={500} disabled={busy}/></label>}
      {(form.kind==='receipt'||(form.kind==='review'&&decision==='confirm'))&&<label className="check-row"><input type="checkbox" checked={ack} onChange={e=>setAck(e.target.checked)} disabled={busy}/>Если такая оплата уже внесена: это ещё одно реальное поступление, я проверил историю.</label>}
      {options.next_page&&['receipt','identify','transfer','refundOpening'].includes(form.kind)&&<button type="button" className="quiet" disabled={busy} onClick={()=>{setBusy(true);void request<Options>(`${base}/payment-options?page=${options.next_page}`).then(d=>setOptions(old=>old?{...d,members:[...(old.members??[]),...(d.members??[])]}:d)).catch(e=>setError(e.message)).finally(()=>setBusy(false));}}>Загрузить ещё участников</button>}
      {form.kind==='adjust'&&form.reconciliation&&<p>Изменение карты: <strong>{fmt(form.reconciliation.current_difference_minor)}</strong>. Сохранится отдельное исправление с указанной причиной.</p>}
      <label className="check-row"><input type="checkbox" checked={confirm} onChange={e=>setConfirm(e.target.checked)} disabled={busy}/>Проверил данные{['claim','template','generate','reconcile'].includes(form.kind)?'':', подтверждаю действие'}</label>
      {error&&<div className="feedback error" role="alert">{error}</div>}
      <button disabled={busy||!confirm}>{busy?'Сохраняем…':'Сохранить'}</button><button type="button" className="quiet" disabled={busy} onClick={()=>setForm(null)}>Закрыть</button>
    </form></section>}
  </section>;
}

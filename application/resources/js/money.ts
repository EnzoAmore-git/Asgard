export function major(value: string, scale: number): string {
  const n = BigInt(value); const digits = (n < 0n ? -n : n).toString().padStart(scale + 1, '0');
  return `${n < 0n ? '-' : ''}${scale ? `${digits.slice(0, -scale)}.${digits.slice(-scale)}` : digits}`;
}

export function minor(value: string, scale: number): string {
  const normalized = value.trim().replace(/[\s\u00a0\u202f]/g, '').replace(/−/g, '-').replace(/^\+/, '').replace(',', '.');
  if (!new RegExp(`^-?\\d+(?:\\.\\d{1,${Math.max(scale, 1)}})?$`).test(normalized) || (scale === 0 && normalized.includes('.'))) {
    throw new Error(scale ? `Введите сумму, например ${scale === 2 ? '2500,50' : `2500,${'0'.repeat(scale - 1)}1`}. Допустимо ${scale} знака после запятой.` : 'Введите сумму целым числом.');
  }
  const negative = normalized.startsWith('-'); const [whole, fraction = ''] = normalized.replace('-', '').split('.');
  const n = BigInt(whole + fraction.padEnd(scale, '0'));
  return (negative && n !== 0n ? '-' : '') + n.toString();
}

export function money(value: string, currency: string, scale: number): string {
  const exact = major(value, scale); const [integer, fraction] = exact.split('.');
  const whole = new Intl.NumberFormat('ru-RU').format(BigInt(integer));
  const sign = exact.startsWith('-') && BigInt(integer) === 0n ? '−' : '';
  return `${sign}${whole}${fraction ? `,${fraction}` : ''} ${currency === 'RUB' ? '₽' : currency}`;
}


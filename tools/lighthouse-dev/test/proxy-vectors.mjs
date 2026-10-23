export const directProxyVectors = [{ path: '/' }, { path: '/synthetic-unowned?value=PRIVATE_TEST', host: 'unowned.invalid' }];
export const proxyUrlVectors = [
  { name: 'origin-form', raw: '/', form: 'origin-form', scheme: 'none', branch: 'parse' },
  { name: 'asterisk', raw: '*', form: 'asterisk', scheme: 'none', branch: 'parse' },
  { name: 'invalid', raw: 'not-a-url', form: 'invalid', scheme: 'none', branch: 'parse' },
  { name: 'network-path', raw: '//mliu:8131/', form: 'invalid', scheme: 'none', branch: 'parse' },
  { name: 'userinfo', raw: 'http://synthetic:PRIVATE_TEST@mliu:8131/', form: 'absolute', scheme: 'http', branch: 'userinfo' },
  { name: 'hash', raw: 'http://mliu:8131/#PRIVATE_TEST', form: 'absolute', scheme: 'http', branch: 'hash' },
  { name: 'scheme', raw: 'ftp://mliu:8131/', form: 'absolute', scheme: 'other', branch: 'scheme' },
];

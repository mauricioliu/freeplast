import { JSDOM } from 'jsdom';

// Called only by the disposable native harness; fixtures never pass through import.
export async function runSalesHistoryTests({ wpEval, check, owner, dataActor, quoteActor, staff, guest, asSitePath, suffix, maintenanceUrl }) {
  const route = '/wp-admin/admin.php?page=fpw-sales-history';
  const id = index => `history-${suffix}-${String(index).padStart(2, '0')}`;
  const receipt = `history-${suffix}`;
  // Refuse to replace even an empty pre-existing register. Cleanup owns only these rows.
  const ready = wpEval(`
    if (null !== fpw_sales_read_row(FPW_SALES_REGISTER_ROW) || null !== fpw_sales_receipt('${receipt}')) {
      throw new RuntimeException('History HTTP fixtures require an absent register and receipt');
    }
    echo 'ready';
  `);
  if (ready !== 'ready') throw new Error('History fixture precondition not established');
  const document = html => new JSDOM(html).window.document;
  const open = async (path, actor = owner) => {
    const response = await actor(path);
    check(response.status === 200, 'history HTTP: screen responds 200: ' + path);
    return document(await response.text());
  };
  const link = (doc, text) => asSitePath([...doc.querySelectorAll('.fpw-history a')].find(a => a.textContent.trim() === text)?.href);
  const rows = doc => [...doc.querySelectorAll('.fpw-history-table tbody a')];
  // Authentication/session/cache rows are deliberately outside this commercial snapshot.
  // Raw values (not decoded JSON) catch even reserialization of sales/receipts/drafts/prices.
  const fingerprint = () => wpEval(`
    global $wpdb;
    $state = array();
    $state['options'] = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name = %s ORDER BY option_name", $wpdb->esc_like('fpw_') . '%', $wpdb->esc_like('woocommerce_') . '%', $wpdb->esc_like('qwc_') . '%', $wpdb->prefix . 'user_roles'), ARRAY_A);
    if ($wpdb->last_error) throw new RuntimeException('Commercial options snapshot failed');
    foreach (array('posts'=>'ID','postmeta'=>'meta_id','comments'=>'comment_ID','commentmeta'=>'meta_id','terms'=>'term_id','termmeta'=>'meta_id','term_taxonomy'=>'term_taxonomy_id','term_relationships'=>'object_id,term_taxonomy_id','woocommerce_order_items'=>'order_item_id','woocommerce_order_itemmeta'=>'meta_id','wc_orders'=>'id','wc_orders_meta'=>'id','wc_order_addresses'=>'id','wc_order_operational_data'=>'id') as $table=>$key) {
      $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table)));
      if ($wpdb->last_error) throw new RuntimeException('Commercial table inspection failed: ' . $table);
      $state[$table] = $present ? $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY {$key}", ARRAY_A) : null;
      if ($wpdb->last_error) throw new RuntimeException('Commercial snapshot failed: ' . $table);
    }
    echo hash('sha256', wp_json_encode($state));
  `);
  try {
    const empty = await open(maintenanceUrl);
    check(empty.querySelector('.fpw-history')?.textContent.includes('Aún no hay ventas históricas'), 'history HTTP: maintenance destination renders empty register without creating it');
    check(wpEval(`echo null === fpw_sales_read_row(FPW_SALES_REGISTER_ROW) ? 'absent' : 'created';`) === 'absent', 'history HTTP: empty browse creates no register');
    wpEval(`
      $sales = array();
      for ($i=0; $i<30; $i++) {
        $name = $i===29 ? 'Otra empresa' : ($i===28 ? 'Vínculo ${suffix}' : 'Cliente ${suffix}');
        $sale = array('id'=>sprintf('history-${suffix}-%02d', $i), 'date'=>$i===27 ? '2025-12-31' : ($i===28 ? '2026-02-01' : ($i%2 ? '2026-01-31' : '2026-01-01')), 'rut'=>$i===27 ? '12.345.678-5' : '76.543.210-K', 'invoice'=>'NATIVE-${suffix}', 'total'=>'1190.1234', 'workbook_source'=>array('filename'=>'synthetic.xlsx', 'sheet'=>'VENTAS', 'sha256'=>str_repeat('a',64), 'rows'=>array(array('source_row'=>2+$i, 'raw'=>array('D'=>$name . ' <img src=x onerror=alert(1)>')))));
        // Same customer can have another source name or no stored name; a namesake can have another RUT.
        if ($i===26) unset($sale['workbook_source']['rows'][0]['raw']['D']);
        if ($i===0) $sale['lines'] = array(
          array('producto'=>'Caja <script>bad</script>', 'total_cajas'=>'2', 'precio'=>'500.0500', 'neto'=>'1000.1000', 'total'=>'1190.1190'),
          array('producto'=>'Ajuste sintético', 'total_cajas'=>'1', 'precio'=>'0.0037', 'neto'=>'0.0037', 'total'=>'0.0044')
        );
        $sales[] = $sale;
      }
      fpw_sales_write_row(FPW_SALES_REGISTER_ROW, array('schema'=>1,'sales'=>$sales));
      fpw_sales_write_row(FPW_SALES_RECEIPT_PREFIX . '${receipt}', array('at'=>1,'actor'=>0,'aplicadas'=>30,'token'=>'${receipt}'));
    `);
    const before = fingerprint();
    const all = await open(maintenanceUrl);
    check(rows(all).length === 25 && all.querySelector('.fpw-history').textContent.includes('30 registros'), 'history HTTP: initial list paginates stored sales');
    check(!all.querySelector('.fpw-history details'), 'history HTTP: list does not render invoice lines/provenance');
    check(all.querySelector('.fpw-history-table[tabindex="0"][role="region"][aria-label]'), 'history HTTP: horizontal table is a named keyboard-focusable region');
    const form = all.querySelector('.fpw-history-filters');
    check(form?.method === 'get' && asSitePath(form.action) === '/wp-admin/admin.php', 'history HTTP: native GET form works without JavaScript');
    for (const name of ['history_search', 'history_from', 'history_to']) {
      check(form.elements[name]?.closest('label')?.textContent.trim(), 'history HTTP: filter has visible associated label: ' + name);
    }
    const params = new URLSearchParams(new all.defaultView.FormData(form));
    params.set('history_search', `Cliente ${suffix}`);
    params.set('history_from', '2026-01-01'); params.set('history_to', '2026-01-31');
    const first = await open('/wp-admin/admin.php?' + params);
    check(rows(first).length === 25 && first.querySelector('.fpw-history').textContent.includes('28 registros · Página 1 de 2'), 'history HTTP: same-RUT association and inclusive dates apply before pagination');
    check(rows(first).find(a => new URL(a.href).searchParams.get('sale_id') === id(29))?.closest('tr').textContent.includes('Otra empresa'), 'history HTTP: same-RUT association preserves the distinct original source name');
    check(rows(first).find(a => new URL(a.href).searchParams.get('sale_id') === id(26))?.closest('tr').textContent.includes('No disponible en origen'), 'history HTTP: same-RUT association recovers nameless legacy sale without inventing its name');
    check(!first.querySelector('.fpw-history img, .fpw-history script') && first.querySelector('.fpw-history').textContent.includes('<img src=x'), 'history HTTP: original client content is escaped');
    const secondPath = link(first, 'Siguiente');
    const second = await open(secondPath);
    check(rows(second).length === 3 && second.querySelector('.fpw-history').textContent.includes('Página 2 de 2'), 'history HTTP: generated next link opens final filtered page');
    const seen = [...rows(first), ...rows(second)].map(a => new URL(a.href).searchParams.get('sale_id'));
    const expected = [...Array.from({length:27}, (_, i) => i), 29].sort((a,b) => (b%2-a%2) || b-a).map(id);
    check(JSON.stringify(seen) === JSON.stringify(expected) && new Set(seen).size === 28, 'history HTTP: exact stable date/ID order, no missing or duplicate associated records');
    const outsideNameParams = new URLSearchParams(params);
    outsideNameParams.set('history_search', `Vínculo ${suffix}`);
    const outsideNameFirst = await open('/wp-admin/admin.php?' + outsideNameParams);
    check(outsideNameFirst.querySelector('.fpw-history').textContent.includes('28 registros · Página 1 de 2'), 'history HTTP: source-name evidence outside the date range still resolves the customer RUT');
    const outsideNameSecond = await open(link(outsideNameFirst, 'Siguiente'));
    const outsideNameIds = [...rows(outsideNameFirst), ...rows(outsideNameSecond)].map(a => new URL(a.href).searchParams.get('sale_id'));
    check(JSON.stringify(outsideNameIds) === JSON.stringify(expected), 'history HTTP: outside-range naming evidence recovers the exact same dated purchases across pages');
    const detailPath = asSitePath(rows(second).find(a => new URL(a.href).searchParams.get('sale_id') === id(0)).href);
    const detail = await open(detailPath + '&redirect_to=https%3A%2F%2Fevil.invalid&unrelated=1');
    const body = detail.querySelector('.fpw-history');
    check(body.textContent.includes('Registro ' + id(0)) && body.textContent.includes('Total de factura') && body.textContent.includes('1.190,1234 CLP') && body.textContent.includes('Total línea: 0,0044 CLP'), 'history HTTP: stable ID opens exact invoice and line amounts');
    check(body.querySelectorAll('section > details ul li').length === 2 && body.textContent.includes('synthetic.xlsx') && body.textContent.includes('Fila original 2'), 'history HTTP: selected multilines and stored provenance render');
    check(!body.querySelector('script, img') && body.textContent.includes('Caja <script>bad</script>'), 'history HTTP: detail product/source content remains escaped');
    const backPath = link(detail, 'Volver al historial');
    check(backPath === secondPath, 'history HTTP: return retains only validated search/date/page, drops ID and foreign parameters');
    const returned = await open(backPath);
    check(JSON.stringify(rows(returned).map(a => a.href)) === JSON.stringify(rows(second).map(a => a.href)), 'history HTTP: back restores identical result page');
    const clear = await open(link(returned, 'Limpiar filtros'));
    check(clear.querySelector('.fpw-history').textContent.includes('30 registros · Página 1'), 'history HTTP: clear restores unfiltered first page');
    const legacy = await open(route + '&sale_id=' + id(1));
    check(legacy.querySelector('.fpw-history').textContent.includes('Desglose por líneas no disponible'), 'history HTTP: legacy absence explicit');
    for (const [search, excluded] of [['76543210k', 27], ['76.543.210-K', 27], [`NATIVE-${suffix}`, null]]) {
      const result = await open(route + '&history_search=' + encodeURIComponent(search));
      const count = excluded === null ? 30 : 29;
      check(result.querySelector('.fpw-history').textContent.includes(count + ' registros'), 'history HTTP: normalized RUT/invoice search: ' + search);
      const next = await open(link(result, 'Siguiente'));
      const actualIds = [...rows(result), ...rows(next)].map(a => new URL(a.href).searchParams.get('sale_id')).sort();
      const expectedIds = Array.from({length:30}, (_, i) => i).filter(i => i !== excluded).map(id).sort();
      check(JSON.stringify(actualIds) === JSON.stringify(expectedIds), 'history HTTP: RUT search excludes the namesake with another RUT while invoice search preserves all matching records: ' + search);
    }
    const namesake = await open(route + '&history_search=12345678-5');
    check(rows(namesake).length === 1 && new URL(rows(namesake)[0].href).searchParams.get('sale_id') === id(27), 'history HTTP: namesake with another normalized RUT stays an independent customer');
    for (const [query, text] of [
      ['history_search=unmatched-' + suffix, 'No hay ventas que coincidan'],
      ['history_from=2026-02-30', 'Parámetros inválidos'],
      ['history_from=2026-02-01&history_to=2026-01-01', 'Parámetros inválidos'],
      ['history_search%5B%5D=x', 'Parámetros inválidos'],
      ['sale_id%5B%5D=x', 'Parámetros inválidos'],
      ['sale_id=missing-' + suffix, 'Registro no encontrado'],
    ]) {
      check((await open(route + '&' + query)).querySelector('.fpw-history').textContent.includes(text), 'history HTTP: safe invalid/absent state: ' + query);
    }
    for (const path of [route, detailPath]) {
      check((await dataActor(path)).status === 200, 'history HTTP: data-only account reads list/detail');
      for (const actor of [staff, quoteActor]) {
        const response = await actor(path);
        check(response.status === 403 && !(await response.text()).includes(id(0)), 'history HTTP: restricted role denied direct list/detail without sale disclosure');
      }
      const response = await guest(path);
      check(response.status === 302 && !(await response.text()).includes(id(0)), 'history HTTP: guest redirected without sale disclosure');
    }
    const importer = await open(link(all, 'Importar ventas'));
    check(importer.querySelector('[name="fpw_sales_nonce"]') && !importer.querySelector('.fpw-history'), 'history HTTP: importer remains separately accessible; no submission');
    check(fingerprint() === before, 'history HTTP: browsing/search/detail/denials/importer GET preserve raw commercial options, sales, receipts, posts and order items');
  } finally {
    wpEval(`delete_option(FPW_SALES_REGISTER_ROW); delete_option(FPW_SALES_RECEIPT_PREFIX . '${receipt}');`);
    check(wpEval(`echo null === fpw_sales_read_row(FPW_SALES_REGISTER_ROW) && null === fpw_sales_receipt('${receipt}') ? 'clean' : 'leaked';`) === 'clean', 'history HTTP: run-owned synthetic register/receipt removed');
  }
}

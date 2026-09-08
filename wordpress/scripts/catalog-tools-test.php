<?php
/** Offline discovery boundary: rendered native-destination forms and actual
 * generated SQL on in-memory SQLite. Not a WordPress/HTTP/browser execution. */
define('ABSPATH', __DIR__);
$filters = $actions = array();
function add_action($name, $callback, ...$args) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter($name, $callback, ...$args) { $GLOBALS['filters'][$name][] = $callback; }
function esc_html($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function is_wp_error($v) { return false; }
function is_admin() { return false; }
function get_search_query($escape=true) { return $GLOBALS['search'] ?? ''; }
function get_query_var($key, $default='') { return $GLOBALS['vars'][$key] ?? $default; }
function get_terms($args) { return array((object)array('slug'=>'agricola','count'=>12), (object)array('slug'=>'otros','count'=>5)); }
function get_term_link($term) { return 'https://example.test/subdir/native-category/'.$term->slug.'/'; }
function wc_get_page_permalink($page) { return 'https://example.test/subdir/shop-real/'; }
function wc_get_products($args) { return (object)array('total'=>16); } // One product belongs to both terms.
function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
function selected($a,$b) { if($a===$b) echo 'selected="selected"'; }
function wc_query_string_form_fields($unused,$excluded) {
    foreach ($_GET as $key=>$value) if(!in_array($key,$excluded,true) && is_string($value)) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
}
function remove_accents($s) { return strtr($s,array('Á'=>'A','á'=>'a','É'=>'E','é'=>'e','Í'=>'I','í'=>'i','Ó'=>'O','ó'=>'o','Ú'=>'U','ú'=>'u')); }
function wc_get_product_visibility_term_ids() { return array('featured'=>99); }
require __DIR__.'/../wp-content/themes/freeplast/functions.php';
$checks=0;
function verify($ok,$message) { global $checks; if(!$ok) throw new RuntimeException($message); $checks++; }
function render_filters() { ob_start(); fp_catalog_filters(); return ob_get_clean(); }
$root=render_filters();
verify(substr_count($root,'class="filter"')===3,'three category controls');
verify(str_contains($root,'Todos <span>16</span>'),'Todos uses native distinct total, not overlapping term sum');
verify(str_contains($root,'/subdir/shop-real/?orderby=menu_order'),'shop URL is native and subdirectory correct');
verify(str_contains($root,'/subdir/native-category/agricola/'),'category URLs come from native terms, not guessed rewrite bases');
$GLOBALS['vars']['product_cat']='agricola'; $GLOBALS['search']='caja <azul>'; $_GET=array('orderby'=>'title','s'=>'caja <azul>','post_type'=>'product','product_cat'=>'agricola','paged'=>'3');
$filtered=render_filters();
verify(substr_count($filtered,'aria-current="page"')===1,'only the selected category is current');
verify(str_contains($filtered,'s=caja+%3Cazul%3E') && str_contains($filtered,'orderby=title'),'category changes retain query and order');
verify(!str_contains($filtered,'paged='),'category changes reset pagination without changing basket');
verify(str_contains(fp_catalog_result_count(0,'<x>'),'0</strong> resultados para «&lt;x&gt;»'),'zero results name escaped query');
// Execute the real ordering template with Woo's real argument names. There
// deliberately is NO invented wc_get_catalog_ordering_options() stub here.
$orderby='title'; $catalog_orderby_options=array('relevance'=>'Relevance','title'=>'Name');
ob_start(); include __DIR__.'/../wp-content/themes/freeplast/woocommerce/loop/orderby.php'; $order=ob_get_clean();
verify(substr_count($order,'<option ')===2 && str_contains($order,'value="title" selected'),'two real choices with selected native value');
foreach(array('s','post_type','product_cat') as $key) verify(str_contains($order,'name="'.$key.'"'),'ordering preserves '.$key);
verify(substr_count($order,'name="paged"')===1 && str_contains($order,'value="1"'),'ordering resets page exactly once');
verify(str_contains($order,'caja &lt;azul&gt;'),'hidden query is escaped once');
class QueryProbe {
    public function __construct(public array $vars) {}
    public function get($key) { return $this->vars[$key] ?? ''; }
    public function set($key,$value) { $this->vars[$key]=$value; }
    public function is_main_query() { return true; }
}
$_GET=array(); $q=new QueryProbe(array('wc_query'=>'product_query','post_type'=>'product','s'=>'PLÁSTICO'));
fp_catalog_prepare_query($q);
verify($q->get('s')==='plastico' && $q->get('fp_a_original_search')==='PLÁSTICO','query folds case/accents while preserving the displayed spelling');
$page=new QueryProbe(array('post_type'=>'page','s'=>'Nosotros')); fp_catalog_prepare_query($page);
verify(!$page->get('fp_a_catalog') && $page->get('s')==='Nosotros','ordinary content query remains untouched');
$wpdb=(object)array('posts'=>'posts','term_relationships'=>'term_relationships');
$db=new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE posts (ID INTEGER,post_title TEXT,post_excerpt TEXT,post_content TEXT,menu_order INTEGER); CREATE TABLE term_relationships (object_id INTEGER,term_taxonomy_id INTEGER);');
$db->exec("INSERT INTO posts VALUES (1,'Caja','','',0),(2,'ÚTIL plástico','','',5),(3,'ÁRBOL','','',9),(4,'Otro','','',0); INSERT INTO term_relationships VALUES (2,99),(3,99);");
$search=$filters['posts_search'][0](" AND (posts.post_title LIKE '%plastico%')",$q);
verify($db->query('SELECT ID FROM posts WHERE 1=1'.$search)->fetchAll(PDO::FETCH_COLUMN)===array(2),'native SQL projection finds an accented product with an unaccented term on SQLite');
$clauses=$filters['posts_clauses'][0](array('orderby'=>'old'),$q);
verify($db->query('SELECT ID FROM posts ORDER BY '.$clauses['orderby'])->fetchAll(PDO::FETCH_COLUMN)===array(2,3,1,4),'featured term wins before nonfeatured menu_order zero');
$q->set('fp_a_orderby','title'); $clauses=$filters['posts_clauses'][0](array('orderby'=>'old'),$q);
verify($db->query('SELECT ID FROM posts ORDER BY '.$clauses['orderby'])->fetchAll(PDO::FETCH_COLUMN)===array(3,1,4,2),'Nombre A–Z folds title accents and sorts the actual native row values');
verify($filters['posts_clauses'][0](array('orderby'=>'unchanged'),$page)['orderby']==='unchanged','ordinary queries retain their native order');
$generic=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/templates/search.html');
verify(str_contains($generic,'wp:post-excerpt') && !str_contains($generic,'archive-product'),'ordinary search renders native content, not product-grid markup');
echo "catalog tools: $checks offline form/query/SQL checks passed (native HTTP unrun)\n";

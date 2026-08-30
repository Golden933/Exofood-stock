<?php
session_start();
$dbPath = __DIR__ . '/data/exofood.sqlite';
if (!is_dir(__DIR__.'/data')) mkdir(__DIR__.'/data', 0777, true);
$db = new PDO('sqlite:'.$dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS products (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT, stock REAL NOT NULL DEFAULT 0,
 unit TEXT NOT NULL DEFAULT 'unité', threshold REAL NOT NULL DEFAULT 0, supplier TEXT, price REAL DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS movements (
 id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, type TEXT NOT NULL, qty REAL NOT NULL,
 reason TEXT, ref TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS suppliers (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, phone TEXT, address TEXT, city TEXT DEFAULT 'Montpellier'
)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (
 key TEXT PRIMARY KEY, value TEXT
)");
$db->exec("CREATE TABLE IF NOT EXISTS stock_value_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT, value REAL NOT NULL, recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
if ((int)$db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn() === 0) {
 $supplierSeed=[
  ['Pangée Market','04 11 75 22 36','9 rue du Pont de Lattes, 34000 Montpellier','Montpellier'],
  ['Ivoir Market','06 44 04 55 22','5 rue du Clos René, 34000 Montpellier','Montpellier'],
  ['O Sandaga Market','09 87 00 27 82','16 rue de la Méditerranée','Montpellier'],
  ['Wei Sin','04 67 06 92 43','45–47 avenue Georges Clemenceau','Montpellier'],
  ['AfrikNdistribution','04 29 82 99 99','18 allée de la Pérouse 93270 Sevran','Sevran']
 ];
 $ss=$db->prepare("INSERT INTO suppliers(name,phone,address,city) VALUES(?,?,?,?)");
 foreach($supplierSeed as $r)$ss->execute($r);
}
$db->exec("INSERT OR IGNORE INTO settings(key,value) VALUES
 ('restaurant_name','ExoFood'),('manager_name','Gérant'),('default_restock_hours','4.5'),('currency','EUR')");


if ((int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn() === 0) {
 $seed = [
 ['Banane plantain','Fruits & Légumes',8,'bananes',10,'Pangée Market',1.20],
 ['Attiéké','Féculents',38,'boules',20,'Ivoir Market',0.80],
 ['Huile 5L','Huiles & Condiments',4,'bidons',2,'Wei Sin',11.50],
 ['Poisson','Poissons & Fruits de mer',2,'cartons',2,'O Sandaga Market',42],
 ['Poulet','Viandes',14,'poulets',10,'AfrikNdistribution',7.50],
 ['Emballage carton plat','Emballages',120,'unités',30,'Pangée Market',0.25],
 ['Bouteille plastique 33cl','Boissons',80,'unités',25,'Pangée Market',0.12],
 ['Bouteille plastique 50cl','Boissons',60,'unités',20,'Pangée Market',0.15],
 ];
 $s=$db->prepare("INSERT INTO products(name,category,stock,unit,threshold,supplier,price) VALUES(?,?,?,?,?,?,?)");
 foreach($seed as $r) $s->execute($r);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
 $action=$_POST['action']??'';
 if ($action==='product') {
   $s=$db->prepare("INSERT INTO products(name,category,stock,unit,threshold,supplier,price) VALUES(?,?,?,?,?,?,?)");
   $s->execute([trim($_POST['name']),trim($_POST['category']),max(0,(float)$_POST['stock']),trim($_POST['unit']),max(0,(float)$_POST['threshold']),trim($_POST['supplier']),max(0,(float)($_POST['price']??0))]);
 }
 if ($action==='product_update') {
   $id=(int)$_POST['id'];
   $st=$db->prepare("UPDATE products SET name=?,category=?,stock=?,unit=?,threshold=?,supplier=?,price=? WHERE id=?");
   $st->execute([trim($_POST['name']),trim($_POST['category']),max(0,(float)$_POST['stock']),trim($_POST['unit']),max(0,(float)$_POST['threshold']),trim($_POST['supplier']),max(0,(float)$_POST['price']),$id]);
 }
 if ($action==='supplier_update') {
   $st=$db->prepare("UPDATE suppliers SET name=?,phone=?,address=?,city=? WHERE id=?");
   $st->execute([trim($_POST['name']),trim($_POST['phone']),trim($_POST['address']),trim($_POST['city']),(int)$_POST['id']]);
 }
 if ($action==='settings_update') {
   $st=$db->prepare("INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)");
   foreach(['restaurant_name','manager_name','default_restock_hours','currency'] as $k) $st->execute([$k,trim($_POST[$k]??'')]);
 }
 if ($action==='movement') {
   $id=(int)$_POST['product_id']; $type=$_POST['type']==='sortie'?'sortie':'entree'; $qty=max(0,(float)$_POST['qty']);
   $p=$db->prepare("SELECT stock FROM products WHERE id=?"); $p->execute([$id]); $stock=(float)$p->fetchColumn();
   $new=$type==='entree' ? $stock+$qty : max(0,$stock-$qty);
   $db->prepare("UPDATE products SET stock=? WHERE id=?")->execute([$new,$id]);
   $db->prepare("INSERT INTO movements(product_id,type,qty,reason,ref) VALUES(?,?,?,?,?)")->execute([$id,$type,$qty,trim($_POST['reason']??''),trim($_POST['ref']??'')]);
   $tv=(float)$db->query("SELECT COALESCE(SUM(stock*price),0) FROM products")->fetchColumn();
   $db->prepare("INSERT INTO stock_value_history(value) VALUES(?)")->execute([$tv]);
 }
 $ret=$_POST['return_page']??'dashboard';
 if(str_contains($ret,'&')){[$rp,$extra]=explode('&',$ret,2);header("Location: /?page=".urlencode($rp)."&".$extra);}
 else header("Location: /?page=".urlencode($ret));
 exit;
}
$products=$db->query("SELECT * FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$movements=$db->query("SELECT m.*,p.name,p.unit FROM movements m JOIN products p ON p.id=m.product_id ORDER BY m.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$page=$_GET['page']??'dashboard';
$allowed=['dashboard','products','product','movements','alerts','suppliers','reports','settings']; if(!in_array($page,$allowed,true))$page='dashboard';
$critical=array_values(array_filter($products,fn($p)=>(float)$p['stock'] <= (float)$p['threshold']));
$totalValue=array_sum(array_map(fn($p)=>(float)$p['stock']*(float)$p['price'],$products));
$suppliers=$db->query("SELECT * FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$settings=[]; foreach($db->query("SELECT key,value FROM settings") as $r)$settings[$r['key']]=$r['value'];
$valueHistory=$db->query("SELECT value,recorded_at FROM stock_value_history ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$valueHistory=array_reverse($valueHistory);
if(!$valueHistory){$valueHistory=[['value'=>$totalValue,'recorded_at'=>date('Y-m-d H:i:s')]];}
$filter=$_GET['filter']??'all';
function pstatus($p){$s=(float)$p['stock'];$t=(float)$p['threshold'];if($s<=0)return 'rupture';if($s<=$t)return 'critique';if($t>0 && $s<=$t*1.5)return 'alerte';return 'sain';}
function phonehref($p){return preg_replace('/[^0-9+]/','',(string)$p);}

function esc($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function n($v){return rtrim(rtrim(number_format((float)$v,2,',',' '),'0'),',');}
function active($p,$x){return $p===$x?'active':'';}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Exo'Food — Gestion des stocks</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#17191d;font:14px Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}.app{display:flex;min-height:100vh}
aside{width:220px;background:#111316;color:white;position:fixed;inset:0 auto 0 0;padding:25px 14px;display:flex;flex-direction:column}.brand{font-size:25px;font-weight:800;padding:0 12px 30px}.brand b{color:#f46b18}.brand small{display:block;font-size:8px;font-weight:500;margin-top:4px;color:#ddd;letter-spacing:.5px}
nav a{display:flex;color:#f5f5f5;text-decoration:none;padding:13px 14px;border-radius:9px;margin:4px 0;font-weight:600;gap:11px;align-items:center}nav a.active{background:#282a2d;color:#ff751c}nav a:hover{background:#232528}.ico{width:21px;text-align:center;font-size:18px}.user{margin-top:auto;border-top:1px solid #292b2e;padding:20px 10px 0}.user b{display:block}.user span{font-size:12px;color:#bbb}
main{margin-left:220px;width:calc(100% - 220px);padding:28px 34px 45px}.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:25px}.top h1{margin:0;font-size:27px}.sub{color:#6c727c;margin-top:6px}.btn{border:1px solid #ddd;background:#fff;border-radius:8px;padding:11px 15px;font-weight:650;cursor:pointer}.btn.orange{background:#f66b0e;color:#fff;border-color:#f66b0e}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.card{background:#fff;border:1px solid #e4e6e9;border-radius:10px;padding:19px;box-shadow:0 1px 2px #00000008}.kpi .label{font-size:12px;color:#555}.kpi strong{display:block;font-size:25px;margin-top:9px}.kpi .trend{font-size:12px;color:#44974b;margin-top:10px}
.two{display:grid;grid-template-columns:1.55fr 1fr;gap:15px;margin-top:15px}.three{display:grid;grid-template-columns:1.3fr .75fr .9fr;gap:15px;margin-top:15px}.card h3{margin:0 0 16px;font-size:16px}.bar{height:9px;background:#eee;border-radius:8px;margin:12px 0;overflow:hidden}.bar i{display:block;height:100%;background:#f77a1b;border-radius:8px}.donut{width:145px;height:145px;border-radius:50%;background:conic-gradient(#4b9e43 0 56%,#ff991a 56% 84%,#e33 84% 100%);position:relative;margin:8px auto}.donut:after{content:"";position:absolute;inset:34px;background:white;border-radius:50%}.donut span{position:absolute;z-index:2;inset:0;display:grid;place-items:center;font-size:22px;font-weight:800}
table{width:100%;border-collapse:collapse;background:#fff}th{text-align:left;color:#34383e;font-size:12px;padding:14px;border-bottom:1px solid #e5e6e8}td{padding:13px 14px;border-bottom:1px solid #eee;font-size:13px}.tablebox{background:white;border:1px solid #e2e4e7;border-radius:10px;overflow:hidden}.badge{display:inline-block;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:650}.ok{background:#eaf6e8;color:#32823a}.bad{background:#fde8e7;color:#d82f29}.warn{background:#fff1d9;color:#c67100}.tabs{display:flex;gap:28px;margin:8px 0 20px;border-bottom:1px solid #ddd}.tabs a{padding:11px 2px;color:#222;text-decoration:none}.tabs .sel{color:#f46b18;border-bottom:2px solid #f46b18;font-weight:700}
.forms{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:7px}.field input,.field select{width:100%;padding:11px;border:1px solid #dcdfe3;border-radius:7px;background:white}.section{margin-top:18px}.alertrow{border-left:4px solid #e43;padding-left:10px}.supplier{font-weight:700}.muted{color:#707782}.hero-product{display:grid;grid-template-columns:180px 1fr;gap:25px}.product-icon{height:150px;background:#f7f7f7;border-radius:12px;display:grid;place-items:center;font-size:80px}.linechart{height:150px;display:flex;align-items:flex-end;gap:8px;padding:15px}.linechart i{flex:1;background:#f47a1b;border-radius:5px 5px 0 0;opacity:.85}
.clickrow{cursor:pointer}.clickrow:hover td{background:#fff8f2}.linkbtn{color:#f46b18;text-decoration:none;font-weight:750}.call{display:inline-block;text-decoration:none;background:#eaf6e8;color:#247c34;padding:7px 10px;border-radius:7px;font-weight:700}.modal{display:none;position:fixed;z-index:20;inset:0;background:#0007;align-items:center;justify-content:center}.modal.open{display:flex}.modalbox{background:white;border-radius:12px;padding:22px;width:min(620px,92vw);max-height:85vh;overflow:auto}.modalbox h3{margin-top:0}.chart{height:220px;display:flex;align-items:flex-end;gap:9px;border-left:1px solid #ddd;border-bottom:1px solid #ddd;padding:15px 15px 0}.chart .col{flex:1;min-width:12px;background:#f46b18;border-radius:5px 5px 0 0;position:relative}.chart .col:hover:after{content:attr(data-v);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#111;color:#fff;padding:4px 6px;border-radius:5px;font-size:11px;white-space:nowrap}
@media(max-width:900px){aside{width:75px}.brand{font-size:0;padding:0 5px 20px}.brand:after{content:"EF";font-size:20px}.brand small,.user,nav a span:not(.ico){display:none}main{margin-left:75px;width:calc(100% - 75px);padding:18px}.grid4,.three,.two,.forms{grid-template-columns:1fr}.top{align-items:flex-start;gap:10px}.tablebox{overflow:auto}}
</style></head><body><div class="app">
<aside><div class="brand">Exo'<b>Food</b><small>RESTAURANT AFRICAIN</small></div><nav>
<a class="<?=active($page,'dashboard')?>" href="/?page=dashboard"><span class="ico">⌂</span><span>Dashboard</span></a>
<a class="<?=active($page,'products')?>" href="/?page=products"><span class="ico">▦</span><span>Produits</span></a>
<a class="<?=active($page,'movements')?>" href="/?page=movements"><span class="ico">⇄</span><span>Mouvements</span></a>
<a class="<?=active($page,'alerts')?>" href="/?page=alerts"><span class="ico">♧</span><span>Alertes (<?=count($critical)?>)</span></a>
<a class="<?=active($page,'suppliers')?>" href="/?page=suppliers"><span class="ico">♙</span><span>Fournisseurs</span></a>
<a class="<?=active($page,'reports')?>" href="/?page=reports"><span class="ico">▥</span><span>Rapports</span></a><a class="<?=active($page,'settings')?>" href="/?page=settings"><span class="ico">⚙</span><span>Paramètres</span></a></nav>
<div class="user"><b>● &nbsp; Gérant</b><span>Administrateur</span></div></aside><main>

<?php if($page==='dashboard'): ?>
<div class="top"><div><h1>Bonjour, Gérant ! 👋</h1><div class="sub">Voici un aperçu de la situation de vos stocks aujourd'hui.</div></div><button class="btn" onclick="document.getElementById('dashFilter').classList.add('open')">⚲ Filtrer</button></div>
<div id="dashFilter" class="modal"><div class="modalbox"><div class="top"><h3>Filtrer le tableau de bord</h3><button class="btn" type="button" onclick="this.closest('.modal').classList.remove('open')">Fermer</button></div><p class="muted">Affichez rapidement les produits selon leur niveau de stock.</p><div class="tabs"><a href="/?page=products&filter=all">Tous</a><a href="/?page=products&filter=sain">Sains</a><a href="/?page=products&filter=alerte">Alerte</a><a href="/?page=products&filter=critique">Critiques</a><a href="/?page=products&filter=rupture">Ruptures</a></div></div></div>
<div class="grid4">
<div class="card kpi"><div class="label">Valeur totale du stock</div><strong><?=number_format($totalValue,2,',',' ')?> €</strong><div class="trend">↑ Suivi en temps réel</div></div>
<div class="card kpi"><div class="label">Produits critiques</div><strong><?=count($critical)?></strong><div class="muted">Voir la liste</div></div>
<div class="card kpi"><div class="label">Alertes actives</div><strong><?=count($critical)?></strong><div class="muted">Voir les alertes</div></div>
<div class="card kpi"><div class="label">Références</div><strong><?=count($products)?></strong><div class="muted">Produits enregistrés</div></div>
</div>
<div class="two"><div class="card"><h3>Évolution de la valeur du stock</h3><div class="linechart"><?php foreach([28,42,37,58,49,65,72,60,78,69,88,96] as $h):?><i style="height:<?=$h?>%"></i><?php endforeach?></div></div>
<div class="card"><h3>Répartition des stocks</h3><div class="donut"><span><?=max(0,count($products)-count($critical))?> OK</span></div></div></div>
<div class="three">
<div class="card"><h3>Produits les plus critiques</h3><table><tr><th>Produit</th><th>Stock</th><th>Seuil</th></tr><?php foreach(array_slice($critical,0,5) as $p):?><tr><td><b><?=esc($p['name'])?></b></td><td style="color:#d33"><?=n($p['stock']).' '.esc($p['unit'])?></td><td><?=n($p['threshold'])?></td></tr><?php endforeach?></table></div>
<div class="card"><h3>Statut des stocks</h3><div class="donut"><span><?=count($products)?round((count($products)-count($critical))/count($products)*100):0?>%</span></div><div style="text-align:center" class="muted">Stocks sains</div></div>
<div class="card"><h3>Catégories par valeur</h3><?php foreach(array_slice($products,0,5) as $i=>$p):?><div><?=esc($p['category'])?><div class="bar"><i style="width:<?=90-$i*12?>%"></i></div></div><?php endforeach?></div>
</div>
<div class="card section"><h3>Derniers mouvements de stock</h3><table><tr><th>Date</th><th>Type</th><th>Produit</th><th>Quantité</th><th>Motif</th></tr><?php foreach(array_slice($movements,0,6) as $m):?><tr><td><?=esc($m['created_at'])?></td><td><span class="badge <?=$m['type']==='entree'?'ok':'bad'?>"><?=ucfirst($m['type'])?></span></td><td><?=esc($m['name'])?></td><td><?=n($m['qty']).' '.esc($m['unit'])?></td><td><?=esc($m['reason'])?></td></tr><?php endforeach?></table></div>

<?php elseif($page==='products'):
$filtered=$products;
if($filter!=='all')$filtered=array_values(array_filter($products,fn($p)=>pstatus($p)===$filter));
?>
<div class="top"><div><h1>Liste des produits</h1><div class="sub">Gérez et suivez tous vos produits en stock. Cliquez sur un produit pour afficher sa fiche.</div></div><button class="btn orange" onclick="document.getElementById('addProduct').scrollIntoView()">＋ Ajouter un produit</button></div>
<div class="tabs"><?php foreach(['all'=>'Tous','sain'=>'Sains','alerte'=>'Alerte','critique'=>'Critique','rupture'=>'Rupture'] as $k=>$lab):?><a class="<?=$filter===$k?'sel':''?>" href="/?page=products&filter=<?=$k?>"><?=$lab?></a><?php endforeach?></div>
<div class="tablebox"><table><tr><th>Produit</th><th>Catégorie</th><th>Quantité en stock</th><th>Unité</th><th>Seuil critique</th><th>Statut</th><th>Fournisseur</th><th></th></tr>
<?php foreach($filtered as $p):$st=pstatus($p);?><tr class="clickrow" onclick="location.href='/?page=product&id=<?=$p['id']?>'"><td><b><?=esc($p['name'])?></b></td><td><?=esc($p['category'])?></td><td><?=n($p['stock'])?></td><td><?=esc($p['unit'])?></td><td><?=n($p['threshold'])?></td><td><span class="badge <?=$st==='sain'?'ok':($st==='alerte'?'warn':'bad')?>"><?=ucfirst($st)?></span></td><td><?=esc($p['supplier'])?></td><td><span class="linkbtn">Voir →</span></td></tr><?php endforeach?></table></div>
<div id="addProduct" class="card section"><h3>Ajouter un produit</h3><form method="post"><input type="hidden" name="action" value="product"><input type="hidden" name="return_page" value="products"><div class="forms">
<div class="field"><label>Produit</label><input required name="name"></div><div class="field"><label>Catégorie</label><input name="category"></div><div class="field"><label>Stock initial</label><input required type="number" step=".01" name="stock"></div><div class="field"><label>Unité</label><input required name="unit" placeholder="unité, carton..."></div>
<div class="field"><label>Seuil critique</label><input required type="number" step=".01" name="threshold"></div><div class="field"><label>Fournisseur</label><select name="supplier"><?php foreach($suppliers as $sp):?><option><?=esc($sp['name'])?></option><?php endforeach?></select></div><div class="field"><label>Prix unitaire (€)</label><input type="number" step=".01" name="price"></div><div class="field" style="align-self:end"><button class="btn orange">Ajouter le produit</button></div></div></form></div>

<?php elseif($page==='product'):
$id=(int)($_GET['id']??0);$q=$db->prepare("SELECT * FROM products WHERE id=?");$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
if(!$p):?><div class="card"><h2>Produit introuvable</h2><a class="linkbtn" href="/?page=products">Retour aux produits</a></div>
<?php else:$st=pstatus($p);$mh=$db->prepare("SELECT * FROM movements WHERE product_id=? ORDER BY id DESC LIMIT 10");$mh->execute([$id]);$ph=$mh->fetchAll(PDO::FETCH_ASSOC);?>
<div class="top"><div><div class="sub"><a class="linkbtn" href="/?page=products">Produits</a> › Détail</div><h1><?=esc($p['name'])?> <span class="badge <?=$st==='sain'?'ok':($st==='alerte'?'warn':'bad')?>"><?=ucfirst($st)?></span></h1></div><button class="btn orange" onclick="document.getElementById('editProduct').classList.add('open')">✎ Modifier</button></div>
<div class="grid4"><div class="card kpi"><div class="label">Stock actuel</div><strong><?=n($p['stock']).' '.esc($p['unit'])?></strong></div><div class="card kpi"><div class="label">Seuil critique</div><strong><?=n($p['threshold']).' '.esc($p['unit'])?></strong></div><div class="card kpi"><div class="label">Prix unitaire</div><strong><?=number_format((float)$p['price'],2,',',' ')?> €</strong></div><div class="card kpi"><div class="label">Valeur du stock</div><strong><?=number_format((float)$p['stock']*(float)$p['price'],2,',',' ')?> €</strong></div></div>
<div class="two"><div class="card section"><h3>Informations générales</h3><table><tr><td><b>Catégorie</b></td><td><?=esc($p['category'])?></td></tr><tr><td><b>Unité</b></td><td><?=esc($p['unit'])?></td></tr><tr><td><b>Fournisseur principal</b></td><td><?=esc($p['supplier'])?></td></tr><tr><td><b>Seuil critique</b></td><td><?=n($p['threshold'])?></td></tr><tr><td><b>Statut</b></td><td><?=ucfirst($st)?></td></tr></table></div><div class="card section"><h3>Lecture financière</h3><p>Stock valorisé : <b><?=number_format((float)$p['stock']*(float)$p['price'],2,',',' ')?> €</b></p><p>Prix unitaire : <b><?=number_format((float)$p['price'],2,',',' ')?> €</b></p><p class="muted">La valorisation évolue automatiquement avec les mouvements de stock.</p></div></div>
<div class="card section"><h3>Historique des mouvements</h3><div class="tablebox"><table><tr><th>Date</th><th>Type</th><th>Quantité</th><th>Motif</th><th>Référence</th></tr><?php foreach($ph as $m):?><tr><td><?=esc($m['created_at'])?></td><td><span class="badge <?=$m['type']==='entree'?'ok':'bad'?>"><?=ucfirst($m['type'])?></span></td><td><?=n($m['qty']).' '.esc($p['unit'])?></td><td><?=esc($m['reason'])?></td><td><?=esc($m['ref'])?></td></tr><?php endforeach?></table></div></div>
<div id="editProduct" class="modal"><div class="modalbox"><div class="top"><h3>Modifier le produit</h3><button class="btn" type="button" onclick="this.closest('.modal').classList.remove('open')">Fermer</button></div><form method="post"><input type="hidden" name="action" value="product_update"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="return_page" value="product&id=<?=$p['id']?>"><div class="forms">
<div class="field"><label>Nom</label><input required name="name" value="<?=esc($p['name'])?>"></div><div class="field"><label>Catégorie</label><input name="category" value="<?=esc($p['category'])?>"></div><div class="field"><label>Stock</label><input type="number" step=".01" name="stock" value="<?=esc($p['stock'])?>"></div><div class="field"><label>Unité</label><input name="unit" value="<?=esc($p['unit'])?>"></div><div class="field"><label>Seuil</label><input type="number" step=".01" name="threshold" value="<?=esc($p['threshold'])?>"></div><div class="field"><label>Fournisseur</label><select name="supplier"><?php foreach($suppliers as $sp):?><option <?=$p['supplier']===$sp['name']?'selected':''?>><?=esc($sp['name'])?></option><?php endforeach?></select></div><div class="field"><label>Prix unitaire (€)</label><input type="number" step=".01" name="price" value="<?=esc($p['price'])?>"></div></div><div style="margin-top:18px"><button class="btn orange">Enregistrer les modifications</button></div></form></div></div>
<?php endif;?>

<?php elseif($page==='movements'): ?>
<div class="top"><div><h1>Mouvements de stock</h1><div class="sub">Consultez l'historique des entrées et sorties ou enregistrez un nouveau mouvement.</div></div></div>
<div class="card"><h3>⊕ Nouveau mouvement de stock</h3><form method="post"><input type="hidden" name="action" value="movement"><input type="hidden" name="return_page" value="movements"><div class="forms">
<div class="field"><label>Type de mouvement</label><select name="type"><option value="entree">↓ Entrée</option><option value="sortie">↑ Sortie</option></select></div>
<div class="field"><label>Produit</label><select required name="product_id"><option value="">Sélectionner</option><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=esc($p['name'])?></option><?php endforeach?></select></div>
<div class="field"><label>Quantité</label><input required type="number" step=".01" min=".01" name="qty"></div><div class="field"><label>Motif</label><input name="reason" placeholder="Réapprovisionnement..."></div>
<div class="field"><label>Référence</label><input name="ref" placeholder="ENT-2026-..."></div><div class="field" style="align-self:end"><button class="btn orange">Enregistrer le mouvement</button></div></div></form></div>
<div class="tablebox section"><table><tr><th>Date & heure</th><th>Type</th><th>Produit</th><th>Quantité</th><th>Motif</th><th>Référence</th></tr><?php foreach($movements as $m):?><tr><td><?=esc($m['created_at'])?></td><td><span class="badge <?=$m['type']==='entree'?'ok':'bad'?>"><?=ucfirst($m['type'])?></span></td><td><b><?=esc($m['name'])?></b></td><td><?=n($m['qty']).' '.esc($m['unit'])?></td><td><?=esc($m['reason'])?></td><td><?=esc($m['ref'])?></td></tr><?php endforeach?></table></div>

<?php elseif($page==='alerts'): ?>
<div class="top"><div><h1>Alertes</h1><div class="sub">Consultez les alertes et contactez directement le fournisseur concerné.</div></div></div>
<div class="grid4"><div class="card kpi"><div class="label">Alertes critiques</div><strong><?=count($critical)?></strong><div class="muted">Nécessitent une action</div></div><div class="card kpi"><div class="label">Stocks faibles</div><strong><?=count(array_filter($products,fn($p)=>pstatus($p)==='alerte'))?></strong><div class="muted">À surveiller</div></div><div class="card kpi"><div class="label">Ruptures</div><strong><?=count(array_filter($products,fn($p)=>pstatus($p)==='rupture'))?></strong></div><div class="card kpi"><div class="label">Total des alertes</div><strong><?=count($critical)?></strong></div></div>
<div class="tablebox section"><table><tr><th>Produit</th><th>Type</th><th>Quantité</th><th>Seuil</th><th>Fournisseur</th><th>Contact rapide</th></tr><?php foreach($critical as $p):$sp=null;foreach($suppliers as $x)if($x['name']===$p['supplier']){$sp=$x;break;}?><tr><td><a class="linkbtn" href="/?page=product&id=<?=$p['id']?>"><?=esc($p['name'])?></a></td><td><span class="badge bad"><?=pstatus($p)==='rupture'?'Rupture':'Stock critique'?></span></td><td style="color:#d22;font-weight:700"><?=n($p['stock']).' '.esc($p['unit'])?></td><td><?=n($p['threshold'])?></td><td><?=esc($p['supplier'])?></td><td><?php if($sp && phonehref($sp['phone'])):?><a class="call" href="tel:<?=phonehref($sp['phone'])?>">☎ <?=esc($sp['phone'])?></a><?php else:?><span class="muted">Numéro non renseigné</span><?php endif?></td></tr><?php endforeach?></table></div>

<?php elseif($page==='suppliers'): ?>
<div class="top"><div><h1>Liste des fournisseurs</h1><div class="sub">Coordonnées et modification des fournisseurs.</div></div></div>
<div class="tablebox"><table><tr><th>Fournisseur</th><th>Téléphone</th><th>Adresse</th><th>Ville</th><th>Contact</th><th>Action</th></tr><?php foreach($suppliers as $sp):?><tr><td class="supplier"><?=esc($sp['name'])?></td><td><?=esc($sp['phone'])?></td><td><?=esc($sp['address'])?></td><td><?=esc($sp['city'])?></td><td><?php if(phonehref($sp['phone'])):?><a class="call" href="tel:<?=phonehref($sp['phone'])?>">☎ Appeler</a><?php endif?></td><td><button class="btn" onclick="document.getElementById('supplier<?=$sp['id']?>').classList.add('open')">✎ Modifier</button></td></tr>
<div id="supplier<?=$sp['id']?>" class="modal"><div class="modalbox"><div class="top"><h3>Modifier <?=esc($sp['name'])?></h3><button class="btn" type="button" onclick="this.closest('.modal').classList.remove('open')">Fermer</button></div><form method="post"><input type="hidden" name="action" value="supplier_update"><input type="hidden" name="id" value="<?=$sp['id']?>"><input type="hidden" name="return_page" value="suppliers"><div class="forms"><div class="field"><label>Nom</label><input name="name" value="<?=esc($sp['name'])?>"></div><div class="field"><label>Téléphone</label><input name="phone" value="<?=esc($sp['phone'])?>"></div><div class="field"><label>Adresse</label><input name="address" value="<?=esc($sp['address'])?>"></div><div class="field"><label>Ville</label><input name="city" value="<?=esc($sp['city'])?>"></div></div><div style="margin-top:18px"><button class="btn orange">Enregistrer</button></div></form></div></div>
<?php endforeach?></table></div>
<div class="card section"><h3>Résumé</h3><p>Fournisseurs suivis : <b><?=count($suppliers)?></b> · Produits référencés : <b><?=count($products)?></b></p><p class="muted">Les numéros sont également disponibles depuis l'écran Alertes pour accélérer le réapprovisionnement.</p></div>

<?php elseif($page==='reports'): 
$entries=array_values(array_filter($movements,fn($m)=>$m['type']==='entree'));
$outs=array_values(array_filter($movements,fn($m)=>$m['type']==='sortie'));
$catValues=[];
foreach($products as $p){$c=$p['category']?:'Autres';$catValues[$c]=($catValues[$c]??0)+((float)$p['stock']*(float)$p['price']);}
arsort($catValues);
?>
<div class="top"><div><h1>Rapports</h1><div class="sub">Analyse synthétique des stocks et des mouvements Exo'Food.</div></div><button class="btn" onclick="window.print()">⤓ Imprimer / PDF</button></div>
<div class="grid4">
<div class="card kpi"><div class="label">Valeur estimée du stock</div><strong><?=number_format($totalValue,2,',',' ')?> €</strong><div class="trend">Calculée à partir des prix renseignés</div></div>
<div class="card kpi"><div class="label">Mouvements enregistrés</div><strong><?=count($movements)?></strong><div class="muted"><?=count($entries)?> entrées / <?=count($outs)?> sorties</div></div>
<div class="card kpi"><div class="label">Produits critiques</div><strong><?=count($critical)?></strong><div class="muted">À réapprovisionner</div></div>
<div class="card kpi"><div class="label">Taux de stocks sains</div><strong><?=count($products)?round((count($products)-count($critical))/count($products)*100):0?>%</strong><div class="muted">Sur <?=count($products)?> références</div></div>
</div>
<div class="card section"><div class="top" style="margin-bottom:8px"><div><h3>Évolution de la valeur du stock</h3><div class="muted">Historique enregistré à chaque mouvement depuis l'activation de ce module.</div></div><b><?=number_format($totalValue,2,',',' ')?> €</b></div><?php $vals=array_column($valueHistory,'value');$mx=max(max($vals),1);?><div class="chart"><?php foreach($valueHistory as $vh):?><div class="col" style="height:<?=max(5,round(((float)$vh['value']/$mx)*100))?>%" data-v="<?=number_format((float)$vh['value'],2,',',' ')?> €"></div><?php endforeach?></div></div>
<div class="two">
<div class="card section"><h3>Valeur du stock par catégorie</h3><?php if(!$catValues):?><p class="muted">Aucune donnée.</p><?php else:$max=max($catValues);foreach($catValues as $cat=>$val):?><div style="display:grid;grid-template-columns:180px 1fr 95px;gap:10px;align-items:center;margin:14px 0"><span><?=esc($cat)?></span><div class="bar" style="margin:0"><i style="width:<?=max(4,round($val/$max*100))?>%"></i></div><b style="text-align:right"><?=number_format($val,2,',',' ')?> €</b></div><?php endforeach;endif;?></div>
<div class="card section"><h3>Répartition des références</h3><div class="donut"><span><?=count($products)?round((count($products)-count($critical))/count($products)*100):0?>%</span></div><p style="text-align:center" class="muted">Part des références au-dessus du seuil critique</p></div>
</div>
<div class="card section"><h3>Produits à surveiller</h3><div class="tablebox"><table><tr><th>Produit</th><th>Stock actuel</th><th>Seuil</th><th>Valeur estimée</th><th>Fournisseur</th></tr><?php foreach($critical as $p):?><tr><td><b><?=esc($p['name'])?></b></td><td><span class="badge bad"><?=n($p['stock']).' '.esc($p['unit'])?></span></td><td><?=n($p['threshold'])?></td><td><?=number_format((float)$p['stock']*(float)$p['price'],2,',',' ')?> €</td><td><?=esc($p['supplier'])?></td></tr><?php endforeach?></table></div></div>

<?php elseif($page==='settings'): ?>
<div class="top"><div><h1>Paramètres</h1><div class="sub">Configuration modifiable du MVP Exo'Food.</div></div><span class="badge ok">Application en ligne</span></div>
<div class="card"><h3>Paramètres généraux</h3><form method="post"><input type="hidden" name="action" value="settings_update"><input type="hidden" name="return_page" value="settings"><div class="forms">
<div class="field"><label>Nom de l'établissement</label><input name="restaurant_name" value="<?=esc($settings['restaurant_name']??'ExoFood')?>"></div>
<div class="field"><label>Nom du profil</label><input name="manager_name" value="<?=esc($settings['manager_name']??'Gérant')?>"></div>
<div class="field"><label>Délai cible de réapprovisionnement (h)</label><input type="number" step=".1" name="default_restock_hours" value="<?=esc($settings['default_restock_hours']??'4.5')?>"></div>
<div class="field"><label>Devise</label><select name="currency"><option value="EUR" <?=($settings['currency']??'EUR')==='EUR'?'selected':''?>>EUR (€)</option></select></div></div><div style="margin-top:18px"><button class="btn orange">Enregistrer les paramètres</button></div></form></div>
<div class="card section"><h3>Seuils par produit</h3><p class="muted">Pour modifier le seuil, l'unité, le prix ou le fournisseur d'un produit, ouvrez directement sa fiche.</p><div class="tablebox"><table><tr><th>Produit</th><th>Unité</th><th>Stock</th><th>Seuil</th><th>État</th><th></th></tr><?php foreach($products as $p):$st=pstatus($p);?><tr><td><b><?=esc($p['name'])?></b></td><td><?=esc($p['unit'])?></td><td><?=n($p['stock'])?></td><td><?=n($p['threshold'])?></td><td><span class="badge <?=$st==='sain'?'ok':($st==='alerte'?'warn':'bad')?>"><?=ucfirst($st)?></span></td><td><a class="linkbtn" href="/?page=product&id=<?=$p['id']?>">Modifier →</a></td></tr><?php endforeach?></table></div></div>
<div class="card section"><h3>Environnement technique</h3><p>PHP 8.3 · SQLite · Docker · Railway</p><p class="muted">Cette URL reste un environnement public de démonstration. N'y saisissez pas de données personnelles sensibles.</p></div>
<?php endif; ?>
</main></div></body></html>
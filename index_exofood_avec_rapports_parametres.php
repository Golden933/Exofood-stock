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
 if ($action==='movement') {
   $id=(int)$_POST['product_id']; $type=$_POST['type']==='sortie'?'sortie':'entree'; $qty=max(0,(float)$_POST['qty']);
   $p=$db->prepare("SELECT stock FROM products WHERE id=?"); $p->execute([$id]); $stock=(float)$p->fetchColumn();
   $new=$type==='entree' ? $stock+$qty : max(0,$stock-$qty);
   $db->prepare("UPDATE products SET stock=? WHERE id=?")->execute([$new,$id]);
   $db->prepare("INSERT INTO movements(product_id,type,qty,reason,ref) VALUES(?,?,?,?,?)")->execute([$id,$type,$qty,trim($_POST['reason']??''),trim($_POST['ref']??'')]);
 }
 header("Location: /?page=".urlencode($_POST['return_page']??'dashboard')); exit;
}
$products=$db->query("SELECT * FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$movements=$db->query("SELECT m.*,p.name,p.unit FROM movements m JOIN products p ON p.id=m.product_id ORDER BY m.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$page=$_GET['page']??'dashboard';
$allowed=['dashboard','products','movements','alerts','suppliers','reports','settings']; if(!in_array($page,$allowed,true))$page='dashboard';
$critical=array_values(array_filter($products,fn($p)=>(float)$p['stock'] <= (float)$p['threshold']));
$totalValue=array_sum(array_map(fn($p)=>(float)$p['stock']*(float)$p['price'],$products));
$suppliers=[
 ['Pangée Market','04 11 75 22 36','9 rue du Pont de Lattes, 34000 Montpellier'],
 ['Ivoir Market','—','5 rue du Clos René, 34000 Montpellier'],
 ['O Sandaga Market','09 87 00 27 82','16 rue de la Méditerranée, Montpellier'],
 ['Wei Sin','04 67 06 92 43','45–47 avenue Georges Clemenceau, Montpellier'],
 ['AfrikNdistribution','—','—']
];
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
table{width:100%;border-collapse:collapse;background:#fff}th{text-align:left;color:#34383e;font-size:12px;padding:14px;border-bottom:1px solid #e5e6e8}td{padding:13px 14px;border-bottom:1px solid #eee;font-size:13px}.tablebox{background:white;border:1px solid #e2e4e7;border-radius:10px;overflow:hidden}.badge{display:inline-block;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:650}.ok{background:#eaf6e8;color:#32823a}.bad{background:#fde8e7;color:#d82f29}.warn{background:#fff1d9;color:#c67100}.tabs{display:flex;gap:28px;margin:8px 0 20px;border-bottom:1px solid #ddd}.tabs span{padding:11px 2px}.tabs .sel{color:#f46b18;border-bottom:2px solid #f46b18;font-weight:700}
.forms{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:7px}.field input,.field select{width:100%;padding:11px;border:1px solid #dcdfe3;border-radius:7px;background:white}.section{margin-top:18px}.alertrow{border-left:4px solid #e43;padding-left:10px}.supplier{font-weight:700}.muted{color:#707782}.hero-product{display:grid;grid-template-columns:180px 1fr;gap:25px}.product-icon{height:150px;background:#f7f7f7;border-radius:12px;display:grid;place-items:center;font-size:80px}.linechart{height:150px;display:flex;align-items:flex-end;gap:8px;padding:15px}.linechart i{flex:1;background:#f47a1b;border-radius:5px 5px 0 0;opacity:.85}
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
<div class="top"><div><h1>Bonjour, Gérant ! 👋</h1><div class="sub">Voici un aperçu de la situation de vos stocks aujourd'hui.</div></div><button class="btn">Filtrer</button></div>
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

<?php elseif($page==='products'): ?>
<div class="top"><div><h1>Liste des produits</h1><div class="sub">Gérez et suivez tous vos produits en stock.</div></div><button class="btn orange" onclick="document.getElementById('addProduct').scrollIntoView()">＋ Ajouter un produit</button></div>
<div class="tabs"><span class="sel">Tous</span><span>Sain</span><span>Alerte</span><span>Critique</span><span>Rupture</span></div>
<div class="tablebox"><table><tr><th>Produit</th><th>Catégorie</th><th>Quantité en stock</th><th>Unité</th><th>Seuil critique</th><th>Statut</th><th>Fournisseur</th></tr>
<?php foreach($products as $p): $is=(float)$p['stock']<=(float)$p['threshold'];?><tr><td><b><?=esc($p['name'])?></b></td><td><?=esc($p['category'])?></td><td><?=n($p['stock'])?></td><td><?=esc($p['unit'])?></td><td><?=n($p['threshold'])?></td><td><span class="badge <?=$is?'bad':'ok'?>"><?=$is?'Critique':'Sain'?></span></td><td><?=esc($p['supplier'])?></td></tr><?php endforeach?></table></div>
<div id="addProduct" class="card section"><h3>Ajouter un produit</h3><form method="post"><input type="hidden" name="action" value="product"><input type="hidden" name="return_page" value="products"><div class="forms">
<div class="field"><label>Produit</label><input required name="name"></div><div class="field"><label>Catégorie</label><input name="category"></div><div class="field"><label>Stock initial</label><input required type="number" step=".01" name="stock"></div><div class="field"><label>Unité</label><input required name="unit" placeholder="unité, carton..."></div>
<div class="field"><label>Seuil critique</label><input required type="number" step=".01" name="threshold"></div><div class="field"><label>Fournisseur</label><input name="supplier"></div><div class="field"><label>Prix unitaire (€)</label><input type="number" step=".01" name="price"></div><div class="field" style="align-self:end"><button class="btn orange">Ajouter le produit</button></div></div></form></div>

<?php elseif($page==='movements'): ?>
<div class="top"><div><h1>Mouvements de stock</h1><div class="sub">Consultez l'historique des entrées et sorties ou enregistrez un nouveau mouvement.</div></div></div>
<div class="card"><h3>⊕ Nouveau mouvement de stock</h3><form method="post"><input type="hidden" name="action" value="movement"><input type="hidden" name="return_page" value="movements"><div class="forms">
<div class="field"><label>Type de mouvement</label><select name="type"><option value="entree">↓ Entrée</option><option value="sortie">↑ Sortie</option></select></div>
<div class="field"><label>Produit</label><select required name="product_id"><option value="">Sélectionner</option><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=esc($p['name'])?></option><?php endforeach?></select></div>
<div class="field"><label>Quantité</label><input required type="number" step=".01" min=".01" name="qty"></div><div class="field"><label>Motif</label><input name="reason" placeholder="Réapprovisionnement..."></div>
<div class="field"><label>Référence</label><input name="ref" placeholder="ENT-2026-..."></div><div class="field" style="align-self:end"><button class="btn orange">Enregistrer le mouvement</button></div></div></form></div>
<div class="tablebox section"><table><tr><th>Date & heure</th><th>Type</th><th>Produit</th><th>Quantité</th><th>Motif</th><th>Référence</th></tr><?php foreach($movements as $m):?><tr><td><?=esc($m['created_at'])?></td><td><span class="badge <?=$m['type']==='entree'?'ok':'bad'?>"><?=ucfirst($m['type'])?></span></td><td><b><?=esc($m['name'])?></b></td><td><?=n($m['qty']).' '.esc($m['unit'])?></td><td><?=esc($m['reason'])?></td><td><?=esc($m['ref'])?></td></tr><?php endforeach?></table></div>

<?php elseif($page==='alerts'): ?>
<div class="top"><div><h1>Alertes</h1><div class="sub">Consultez et gérez les alertes de votre stock.</div></div><button class="btn">✓ Marquer tout comme lu</button></div>
<div class="grid4"><div class="card kpi"><div class="label">Alertes critiques</div><strong><?=count($critical)?></strong><div class="muted">Nécessitent une action</div></div><div class="card kpi"><div class="label">Stocks faibles</div><strong><?=count($critical)?></strong><div class="muted">À réapprovisionner</div></div><div class="card kpi"><div class="label">Prochainement expirés</div><strong>0</strong><div class="muted">Suivi à compléter</div></div><div class="card kpi"><div class="label">Total des alertes</div><strong><?=count($critical)?></strong><div class="muted">Alertes actives</div></div></div>
<div class="tablebox section"><table><tr><th>Produit</th><th>Type d'alerte</th><th>Quantité actuelle</th><th>Seuil</th><th>Fournisseur</th><th>Statut</th></tr><?php foreach($critical as $p):?><tr><td><b><?=esc($p['name'])?></b></td><td><span class="badge bad">Stock critique</span></td><td style="color:#d22;font-weight:700"><?=n($p['stock']).' '.esc($p['unit'])?></td><td><?=n($p['threshold'])?></td><td><?=esc($p['supplier'])?></td><td><span class="badge warn">À traiter</span></td></tr><?php endforeach?></table></div>

<?php elseif($page==='suppliers'): ?>
<div class="top"><div><h1>Liste des fournisseurs</h1><div class="sub">Gérez vos fournisseurs et consultez leurs informations.</div></div></div>
<div class="tablebox"><table><tr><th>Fournisseur</th><th>Téléphone</th><th>Adresse</th><th>Ville</th><th>Statut</th></tr><?php foreach($suppliers as $s):?><tr><td class="supplier"><?=esc($s[0])?></td><td><?=esc($s[1])?></td><td><?=esc($s[2])?></td><td>Montpellier</td><td><span class="badge ok">Actif</span></td></tr><?php endforeach?></table></div>
<div class="two"><div class="card section"><h3>Pangée Market</h3><p><b>Fournisseur alimentaire</b></p><p>☎ 04 11 75 22 36</p><p>⌖ 9 rue du Pont de Lattes<br>34000 Montpellier</p></div><div class="card section"><h3>Résumé</h3><p>Fournisseurs suivis : <b><?=count($suppliers)?></b></p><p>Produits référencés : <b><?=count($products)?></b></p><p class="badge ok">Fournisseurs actifs</p></div></div>

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
<div class="two">
<div class="card section"><h3>Valeur du stock par catégorie</h3><?php if(!$catValues):?><p class="muted">Aucune donnée.</p><?php else:$max=max($catValues);foreach($catValues as $cat=>$val):?><div style="display:grid;grid-template-columns:180px 1fr 95px;gap:10px;align-items:center;margin:14px 0"><span><?=esc($cat)?></span><div class="bar" style="margin:0"><i style="width:<?=max(4,round($val/$max*100))?>%"></i></div><b style="text-align:right"><?=number_format($val,2,',',' ')?> €</b></div><?php endforeach;endif;?></div>
<div class="card section"><h3>Répartition des références</h3><div class="donut"><span><?=count($products)?round((count($products)-count($critical))/count($products)*100):0?>%</span></div><p style="text-align:center" class="muted">Part des références au-dessus du seuil critique</p></div>
</div>
<div class="card section"><h3>Produits à surveiller</h3><div class="tablebox"><table><tr><th>Produit</th><th>Stock actuel</th><th>Seuil</th><th>Valeur estimée</th><th>Fournisseur</th></tr><?php foreach($critical as $p):?><tr><td><b><?=esc($p['name'])?></b></td><td><span class="badge bad"><?=n($p['stock']).' '.esc($p['unit'])?></span></td><td><?=n($p['threshold'])?></td><td><?=number_format((float)$p['stock']*(float)$p['price'],2,',',' ')?> €</td><td><?=esc($p['supplier'])?></td></tr><?php endforeach?></table></div></div>

<?php elseif($page==='settings'): ?>
<div class="top"><div><h1>Paramètres</h1><div class="sub">Configuration générale et règles de gestion du MVP Exo'Food.</div></div><span class="badge ok">Application en ligne</span></div>
<div class="two">
<div class="card"><h3>Informations de l'application</h3>
<table><tr><td><b>Application</b></td><td>Exo'Food — Gestion des stocks</td></tr><tr><td><b>Profil de démonstration</b></td><td>Gérant / Administrateur</td></tr><tr><td><b>Environnement</b></td><td>Production — Railway</td></tr><tr><td><b>Back-end</b></td><td>PHP 8.3</td></tr><tr><td><b>Base de données</b></td><td>SQLite</td></tr><tr><td><b>Conteneurisation</b></td><td>Docker</td></tr></table>
</div>
<div class="card"><h3>Règles générales</h3><p><b>Seuil d'alerte :</b> chaque produit possède son propre seuil critique.</p><p><b>Mouvement d'entrée :</b> augmente automatiquement le stock.</p><p><b>Mouvement de sortie :</b> diminue le stock sans passer sous zéro.</p><p><b>Alerte :</b> activée lorsque le stock est inférieur ou égal au seuil.</p><p class="muted">Ces paramètres sont affichés dans le MVP. Leur modification centralisée est prévue dans une évolution ultérieure.</p></div>
</div>
<div class="card section"><h3>Seuils configurés par produit</h3><div class="tablebox"><table><tr><th>Produit</th><th>Unité</th><th>Stock actuel</th><th>Seuil critique</th><th>État</th></tr><?php foreach($products as $p):$is=(float)$p['stock']<=(float)$p['threshold'];?><tr><td><b><?=esc($p['name'])?></b></td><td><?=esc($p['unit'])?></td><td><?=n($p['stock'])?></td><td><?=n($p['threshold'])?></td><td><span class="badge <?=$is?'bad':'ok'?>"><?=$is?'Alerte':'Sain'?></span></td></tr><?php endforeach?></table></div></div>
<div class="card section"><h3>Sécurité et accès</h3><p>Cette URL correspond à un environnement public de démonstration destiné à l'évaluation du MVP. Aucune donnée personnelle sensible ne doit y être saisie.</p><p class="muted">Une authentification, une gestion fine des rôles et des sauvegardes persistantes font partie des améliorations prévues avant un usage métier en production réelle.</p></div>
<?php endif; ?>
</main></div></body></html>
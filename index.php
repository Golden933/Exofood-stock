<?php
$dbFile=__DIR__.'/data/exofood.sqlite';
$first=!file_exists($dbFile);
$db=new PDO('sqlite:'.$dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,unit TEXT NOT NULL,stock REAL NOT NULL DEFAULT 0,threshold REAL NOT NULL DEFAULT 0,supplier TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS movements(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,type TEXT NOT NULL,quantity REAL NOT NULL,created_at TEXT NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id))");
if($first){
 $seed=[
 ['Banane plantain','unité',8,10,'Pangée Market'],
 ['Attiéké','boule',18,20,'Ivoir Market'],
 ['Huile 5 L','bidon',4,2,'Wei Sin'],
 ['Poisson','carton',2,2,'O Sandaga Market'],
 ['Poulet','unité',14,10,'AfrikNdistribution'],
 ['Emballage carton plat','unité',120,30,'Pangée Market'],
 ['Bouteille plastique 33 cl','unité',80,25,'Pangée Market'],
 ['Bouteille plastique 50 cl','unité',60,20,'Pangée Market']
 ];
 $st=$db->prepare("INSERT INTO products(name,unit,stock,threshold,supplier) VALUES(?,?,?,?,?)");
 foreach($seed as $x)$st->execute($x);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'';
 if($action==='movement'){
   $id=(int)($_POST['product_id']??0); $type=$_POST['type']??''; $q=(float)($_POST['quantity']??0);
   if($id&&$q>0&&in_array($type,['entree','sortie'])){
     $p=$db->prepare("SELECT * FROM products WHERE id=?");$p->execute([$id]);$prod=$p->fetch(PDO::FETCH_ASSOC);
     if($prod){
       $new=$type==='entree'?$prod['stock']+$q:$prod['stock']-$q;
       if($new>=0){
         $db->beginTransaction();
         $db->prepare("UPDATE products SET stock=? WHERE id=?")->execute([$new,$id]);
         $db->prepare("INSERT INTO movements(product_id,type,quantity,created_at) VALUES(?,?,?,datetime('now'))")->execute([$id,$type,$q]);
         $db->commit();
       }
     }
   }
 }
 if($action==='product'){
   $name=trim($_POST['name']??'');$unit=trim($_POST['unit']??'unité');$stock=(float)($_POST['stock']??0);$threshold=(float)($_POST['threshold']??0);$supplier=trim($_POST['supplier']??'');
   if($name!=='')$db->prepare("INSERT INTO products(name,unit,stock,threshold,supplier) VALUES(?,?,?,?,?)")->execute([$name,$unit,$stock,$threshold,$supplier]);
 }
 header("Location: /");exit;
}
$products=$db->query("SELECT * FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$movements=$db->query("SELECT m.*,p.name FROM movements m JOIN products p ON p.id=m.product_id ORDER BY m.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$critical=array_values(array_filter($products,fn($p)=>$p['stock']<=$p['threshold']));
$total=count($products); $ok=$total-count($critical);
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Exo'Food — Gestion des stocks</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f5f6f8;color:#191919}.layout{display:grid;grid-template-columns:230px 1fr;min-height:100vh}
aside{background:#171717;color:#fff;padding:28px 18px}.logo{font-size:25px;font-weight:800;margin-bottom:35px}.logo span{color:#ff7a00}.nav{display:block;padding:12px;border-radius:10px;margin:5px 0;color:#fff;text-decoration:none}.nav:hover,.nav.active{background:#ff7a00}
main{padding:30px;max-width:1300px}.top{display:flex;justify-content:space-between;align-items:center}.muted{color:#777}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin:22px 0}.card,.panel{background:#fff;border:1px solid #e6e6e6;border-radius:14px;padding:20px}.big{font-size:30px;font-weight:800}.danger{color:#c62828}.good{color:#16823b}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:18px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:11px;border-bottom:1px solid #eee;font-size:14px}.badge{padding:5px 9px;border-radius:20px;background:#eaf7ee;color:#16823b;font-size:12px}.badge.bad{background:#ffeded;color:#c62828}
input,select{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;margin:5px 0 10px}button{background:#ff7a00;color:white;border:0;padding:11px 14px;border-radius:9px;font-weight:700;cursor:pointer}.forms{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px}
@media(max-width:800px){.layout{grid-template-columns:1fr}aside{display:none}main{padding:16px}.cards,.grid,.forms{grid-template-columns:1fr}.panel{overflow:auto}}
</style></head><body><div class="layout"><aside><div class="logo">Exo<span>'Food</span></div><a class="nav active" href="#dashboard">Dashboard</a><a class="nav" href="#produits">Produits</a><a class="nav" href="#mouvements">Mouvements</a><a class="nav" href="#alertes">Alertes</a><a class="nav" href="#fournisseurs">Fournisseurs</a></aside>
<main id="dashboard"><div class="top"><div><h1>Gestion des stocks</h1><div class="muted">Tableau de bord — Exo'Food Montpellier</div></div><div>Administrateur</div></div>
<div class="cards"><div class="card"><div class="muted">Produits suivis</div><div class="big"><?=$total?></div></div><div class="card"><div class="muted">Stocks normaux</div><div class="big good"><?=$ok?></div></div><div class="card"><div class="muted">Alertes critiques</div><div class="big danger"><?=count($critical)?></div></div></div>
<div class="grid"><section class="panel" id="produits"><h2>Produits</h2><table><tr><th>Produit</th><th>Stock</th><th>Seuil</th><th>Fournisseur</th><th>Statut</th></tr>
<?php foreach($products as $p): $bad=$p['stock']<=$p['threshold'];?><tr><td><b><?=htmlspecialchars($p['name'])?></b></td><td><?=$p['stock'].' '.htmlspecialchars($p['unit'])?></td><td><?=$p['threshold']?></td><td><?=htmlspecialchars($p['supplier'])?></td><td><span class="badge <?=$bad?'bad':''?>"><?=$bad?'Critique':'OK'?></span></td></tr><?php endforeach;?></table></section>
<section class="panel" id="alertes"><h2>Alertes</h2><?php if(!$critical):?><p class="good">Aucune alerte.</p><?php endif;?><?php foreach($critical as $p):?><p><b class="danger"><?=htmlspecialchars($p['name'])?></b><br><span class="muted">Stock <?=$p['stock']?> / seuil <?=$p['threshold']?></span></p><?php endforeach;?></section></div>
<div class="forms" id="mouvements"><section class="panel"><h2>Nouveau mouvement</h2><form method="post"><input type="hidden" name="action" value="movement"><label>Produit</label><select name="product_id"><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach;?></select><label>Type</label><select name="type"><option value="entree">Entrée</option><option value="sortie">Sortie</option></select><label>Quantité</label><input type="number" step="0.01" min="0.01" name="quantity" required><button>Enregistrer le mouvement</button></form></section>
<section class="panel"><h2>Ajouter un produit</h2><form method="post"><input type="hidden" name="action" value="product"><input name="name" placeholder="Nom du produit" required><input name="unit" placeholder="Unité (carton, unité...)"><input type="number" step="0.01" name="stock" placeholder="Stock initial"><input type="number" step="0.01" name="threshold" placeholder="Seuil critique"><input name="supplier" placeholder="Fournisseur"><button>Ajouter le produit</button></form></section></div>
<section class="panel" style="margin-top:18px"><h2>Derniers mouvements</h2><table><tr><th>Date</th><th>Produit</th><th>Type</th><th>Quantité</th></tr><?php foreach($movements as $m):?><tr><td><?=$m['created_at']?></td><td><?=htmlspecialchars($m['name'])?></td><td><?=$m['type']==='entree'?'Entrée':'Sortie'?></td><td><?=$m['quantity']?></td></tr><?php endforeach;?></table></section>
<section class="panel" id="fournisseurs" style="margin-top:18px"><h2>Fournisseurs</h2><table><tr><th>Fournisseur</th><th>Produits associés</th></tr><?php $sup=[]; foreach($products as $p){$n=$p['supplier']?:'Non renseigné';$sup[$n][]=$p['name'];} foreach($sup as $n=>$items): ?><tr><td><b><?=htmlspecialchars($n)?></b></td><td><?=htmlspecialchars(implode(', ',$items))?></td></tr><?php endforeach;?></table></section>
</main></div><script>document.querySelectorAll('.nav').forEach(a=>a.addEventListener('click',()=>{document.querySelectorAll('.nav').forEach(x=>x.classList.remove('active'));a.classList.add('active')}));</script></body></html>
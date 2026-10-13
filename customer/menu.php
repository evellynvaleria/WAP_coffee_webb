<?php
require_once __DIR__ . '/_layout.php';

// ASUMSI tabel: menu(id,name,category,price,image,is_available), orders(...), order_items(order_id,menu_id,size,qty,price)
// ---- Place order (AJAX) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $in = json_decode(file_get_contents('php://input'), true);
  $items = $in['items'] ?? [];
  if (!$items) { echo json_encode(['ok'=>false,'msg'=>'Keranjang kosong']); exit; }
  $type = ($in['type'] ?? '') === 'Take away' ? 'Take away' : 'Dine In';
  $uid  = (int)$_SESSION['user_id'];
  $conn->begin_transaction();
  try {
    $total = 0; $rows = [];
    foreach ($items as $it) {               // harga selalu dihitung ulang di server
      $st = $conn->prepare("SELECT price,is_available FROM menu WHERE id=?");
      $st->bind_param('i', $it['id']); $st->execute();
      $m = $st->get_result()->fetch_assoc();
      if (!$m || !$m['is_available']) throw new Exception('Ada menu yang tidak tersedia');
      $qty = max(1, (int)$it['qty']);
      $mult = ['S'=>0.8,'M'=>1,'L'=>1.2][$it['size']] ?? 1;
      $price = round($m['price'] * $mult / 1000) * 1000;
      $total += $price * $qty; $rows[] = [$it['id'],$it['size'],$qty,$price];
    }
    $name = $_SESSION['name'] ?? 'Customer';
    $st = $conn->prepare("INSERT INTO orders (customer_id,customer_name,order_type,total,order_status,created_at) VALUES (?,?,?,?, 'Pending', NOW())");
    $st->bind_param('issi', $uid, $name, $type, $total); $st->execute();
    $oid = $conn->insert_id;
    $st = $conn->prepare("INSERT INTO order_items (order_id,menu_id,size,qty,price) VALUES (?,?,?,?,?)");
    foreach ($rows as $r) { $st->bind_param('iisii', $oid, $r[0], $r[1], $r[2], $r[3]); $st->execute(); }
    $conn->commit();
    echo json_encode(['ok'=>true,'id'=>$oid]);
  } catch (Exception $e) { $conn->rollback(); echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
  exit;
}

$menus = $conn->query("SELECT * FROM menu ORDER BY category,name")->fetch_all(MYSQLI_ASSOC);
$cats = array_values(array_unique(array_column($menus, 'category')));
layout_start('Menu', 'menu');
?>
<div class="row g-4">
  <div class="col-xl-8">
    <h4 class="fw-bold">Mau minum apa hari ini?</h4>
    <p class="text-muted">Pilih menu favoritmu, lalu pesan langsung dari sini.</p>
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
      <button class="chip active" data-cat="">All</button>
      <?php foreach($cats as $c): ?><button class="chip" data-cat="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?></button><?php endforeach; ?>
      <input id="q" class="form-control ms-auto" style="max-width:240px;border-radius:12px" placeholder="Search menu...">
    </div>
    <div class="row g-3" id="grid">
      <?php foreach($menus as $m): $off = !$m['is_available']; ?>
      <div class="col-md-6 col-lg-4 mi" data-cat="<?=htmlspecialchars($m['category'])?>" data-name="<?=strtolower(htmlspecialchars($m['name']))?>">
        <div class="item <?=$off?'off':''?>" data-id="<?=$m['id']?>" data-n="<?=htmlspecialchars($m['name'])?>" data-p="<?=$m['price']?>">
          <img src="../uploads/<?=htmlspecialchars($m['image'])?>" alt="">
          <div class="p-3">
            <div class="fw-bold"><?=htmlspecialchars($m['name'])?></div>
            <div class="text-muted small mb-2"><?=htmlspecialchars($m['category'])?></div>
            <div class="d-flex gap-2 mb-3">
              <?php foreach(['S','M','L'] as $s): ?><button class="size <?=$s==='M'?'active':''?>" data-s="<?=$s?>" <?=$off?'disabled':''?>><?=$s?></button><?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <span class="fw-bold price"><?=rp($m['price'])?></span>
              <?php if($off): ?><span class="small text-danger">Tidak tersedia</span>
              <?php else: ?><button class="btn btn-luma btn-sm add"><i class="bi bi-plus-lg"></i> Tambah</button><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="cardx cart">
      <h5 class="fw-bold mb-3">Pesanan Saya</h5>
      <div class="d-flex gap-2 mb-3">
        <button class="btn btn-luma flex-fill type" data-t="Dine In">Dine in</button>
        <button class="btn btn-outline-secondary flex-fill type" data-t="Take away" style="border-radius:12px">Take away</button>
      </div>
      <div id="lines" class="text-muted small mb-3">Belum ada item</div>
      <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span>Total</span><span id="total">Rp 0</span></div>
      <button id="order" class="btn btn-luma w-100 py-2" disabled>Pesan Sekarang</button>
      <div id="msg" class="small mt-2"></div>
    </div>
  </div>
</div>

<script>
const mult={S:.8,M:1,L:1.2}, cart=[]; let type='Dine In';
const rp=n=>'Rp '+n.toLocaleString('id-ID');
const unit=(p,s)=>Math.round(p*mult[s]/1000)*1000;

// filter & search
let cat='';
function filter(){const q=document.getElementById('q').value.toLowerCase();
  document.querySelectorAll('.mi').forEach(e=>e.style.display=(!cat||e.dataset.cat===cat)&&e.dataset.name.includes(q)?'':'none');}
document.querySelectorAll('[data-cat]').forEach(b=>{if(b.classList.contains('chip'))b.onclick=()=>{
  document.querySelectorAll('.chip').forEach(x=>x.classList.remove('active'));b.classList.add('active');cat=b.dataset.cat;filter();}});
document.getElementById('q').oninput=filter;

// size picker + harga live
document.querySelectorAll('.item').forEach(card=>{
  card.querySelectorAll('.size').forEach(b=>b.onclick=()=>{
    card.querySelectorAll('.size').forEach(x=>x.classList.remove('active'));b.classList.add('active');
    card.querySelector('.price').textContent=rp(unit(+card.dataset.p,b.dataset.s));});
  const add=card.querySelector('.add');
  if(add)add.onclick=()=>{const s=card.querySelector('.size.active').dataset.s,id=+card.dataset.id;
    const ex=cart.find(c=>c.id===id&&c.size===s);
    ex?ex.qty++:cart.push({id,size:s,qty:1,name:card.dataset.n,price:unit(+card.dataset.p,s)});render();};
});

// dine in / take away
document.querySelectorAll('.type').forEach(b=>b.onclick=()=>{type=b.dataset.t;
  document.querySelectorAll('.type').forEach(x=>{x.className='btn flex-fill type '+(x===b?'btn-luma':'btn-outline-secondary')});});

function render(){
  const box=document.getElementById('lines');
  box.className=cart.length?'mb-3':'text-muted small mb-3';
  box.innerHTML=cart.length?cart.map((c,i)=>`<div class="d-flex justify-content-between align-items-center py-2 border-bottom">
    <div><div class="fw-semibold">${c.name} <small class="text-muted">(${c.size})</small></div><small>${rp(c.price)}</small></div>
    <div class="d-flex align-items-center gap-2"><button class="btn btn-sm btn-light" onclick="chg(${i},-1)">−</button>${c.qty}
    <button class="btn btn-sm btn-light" onclick="chg(${i},1)">+</button></div></div>`).join(''):'Belum ada item';
  document.getElementById('total').textContent=rp(cart.reduce((a,c)=>a+c.price*c.qty,0));
  document.getElementById('order').disabled=!cart.length;
}
function chg(i,d){cart[i].qty+=d;if(cart[i].qty<1)cart.splice(i,1);render();}

document.getElementById('order').onclick=async()=>{
  const btn=document.getElementById('order'),msg=document.getElementById('msg');btn.disabled=true;
  const r=await fetch('menu.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type,items:cart})}).then(r=>r.json());
  if(r.ok){location.href='orders.php?new='+r.id;}else{msg.className='small mt-2 text-danger';msg.textContent=r.msg;btn.disabled=false;}
};
</script>
<?php layout_end();

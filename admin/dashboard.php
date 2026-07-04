<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
$page_title = 'Dashboard';
include __DIR__ . '/layout.php';

// ══════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════
$meses_es = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

// Últimas 8 semanas ISO (lunes→domingo)
$semanas = [];
for ($i = 7; $i >= 0; $i--) {
    $ref       = strtotime("-$i weeks");
    $dow       = (int)date('N', $ref);
    $lunes_ts  = $ref - ($dow - 1) * 86400;
    $dom_ts    = $lunes_ts + 6 * 86400;
    $yw        = (int)date('oW', $lunes_ts);
    $semanas[] = [
        'yw'    => $yw,
        'label' => date('d', $lunes_ts) . '-' . date('d', $dom_ts) . ' ' . $meses_es[(int)date('n', $lunes_ts)],
    ];
}

// ══════════════════════════════════════════════════════════════════
// QUERIES (solo simpatizantes, personeros y credenciales)
// ══════════════════════════════════════════════════════════════════
try {
    $total_simpatizantes = (int)$pdo->query("SELECT COUNT(*) FROM simpatizantes")->fetchColumn();
    $total_personeros    = (int)$pdo->query("SELECT COUNT(*) FROM personeros WHERE estado='activo'")->fetchColumn();
    $total_credenciales  = (int)$pdo->query("SELECT COUNT(*) FROM credenciales WHERE estado='activo'")->fetchColumn();
    $total_credenciales_escaneadas = (int)$pdo->query("SELECT COUNT(*) FROM credenciales_escaneadas")->fetchColumn();

    // Simpatizantes por semana
    $simp_w_map = $pdo->query(
        "SELECT YEARWEEK(fecha_registro,1) as yw, COUNT(*) as total
         FROM simpatizantes WHERE fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
         GROUP BY yw ORDER BY yw"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $simp_w_data = array_map(fn($s) => (int)($simp_w_map[$s['yw']] ?? 0), $semanas);

    // Simpatizantes por semana + distrito (stacked, top-5 + Otros)
    $dist_totales = $pdo->query(
        "SELECT distrito, COUNT(*) as total FROM simpatizantes GROUP BY distrito ORDER BY total DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    $top5_dist  = array_column($dist_totales, 'distrito');
    $dist_raw_all = $pdo->query(
        "SELECT YEARWEEK(fecha_registro,1) as yw, distrito, COUNT(*) as total
         FROM simpatizantes WHERE fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
         GROUP BY yw, distrito ORDER BY yw"
    )->fetchAll(PDO::FETCH_ASSOC);
    $simp_stacked = [];
    foreach ($top5_dist as $d) {
        $s = [];
        foreach ($semanas as $sem) {
            $v = 0;
            foreach ($dist_raw_all as $r) {
                if ($r['distrito'] === $d && (int)$r['yw'] === $sem['yw']) { $v = (int)$r['total']; break; }
            }
            $s[] = $v;
        }
        $simp_stacked[] = ['name' => $d, 'data' => $s];
    }
    $otros_s = [];
    foreach ($semanas as $sem) {
        $ot = 0;
        foreach ($dist_raw_all as $r) {
            if (!in_array($r['distrito'], $top5_dist) && (int)$r['yw'] === $sem['yw']) $ot += (int)$r['total'];
        }
        $otros_s[] = $ot;
    }
    if (array_sum($otros_s) > 0) $simp_stacked[] = ['name'=>'Otros','data'=>$otros_s];

    // Ranking distritos total
    $dist_rank = $pdo->query(
        "SELECT distrito, COUNT(*) as total FROM simpatizantes GROUP BY distrito ORDER BY total DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
    $dist_labels = array_column($dist_rank, 'distrito');
    $dist_data   = array_map('intval', array_column($dist_rank, 'total'));

    // Personeros por semana
    $pers_w_map = $pdo->query(
        "SELECT YEARWEEK(creado_en,1) as yw, COUNT(*) as total
         FROM personeros WHERE creado_en >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
         GROUP BY yw ORDER BY yw"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $pers_w_data = array_map(fn($s) => (int)($pers_w_map[$s['yw']] ?? 0), $semanas);

    // Credenciales por semana
    $cred_w_map = $pdo->query(
        "SELECT YEARWEEK(creado_en,1) as yw, COUNT(*) as total
         FROM credenciales WHERE creado_en >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
         GROUP BY yw ORDER BY yw"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $cred_w_data = array_map(fn($s) => (int)($cred_w_map[$s['yw']] ?? 0), $semanas);

    // Feed actividad reciente
    $activity_feed = $pdo->query(
        "SELECT usuario_nombre, accion, modulo, creado_en
         FROM activity_logs ORDER BY id DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $total_simpatizantes = $total_personeros = $total_credenciales = $total_credenciales_escaneadas = 0;
    $simp_w_data = $pers_w_data = $cred_w_data = [];
    $simp_stacked = $dist_labels = $dist_data = [];
    $activity_feed = [];
}

$week_labels = array_column($semanas, 'label');

function dash_time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'hace '.(int)$diff.'s';
    if ($diff < 3600)  return 'hace '.floor($diff/60).'min';
    if ($diff < 86400) return 'hace '.floor($diff/3600).'h';
    return 'hace '.floor($diff/86400).'d';
}
?>

<style>
  [x-cloak]{display:none!important}
  .kpi-card{min-height:150px;transition:transform .18s ease,box-shadow .18s ease}
  .kpi-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(30,58,138,.18)}
  .shortcut-card{transition:transform .18s ease,box-shadow .18s ease,opacity .18s}
  .shortcut-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,.18);opacity:.92}
  .chart-card{background:#fff;border-radius:1.25rem;border:1px solid #f1f5f9;padding:1.5rem}
  .feed-line{border-left:2px solid #E5E7EB;padding-left:1rem;position:relative}
  .feed-dot{position:absolute;left:-5px;top:6px;width:8px;height:8px;border-radius:50%;background:#1E3A8A;border:2px solid #fff}
  .counter{font-variant-numeric:tabular-nums}
  @media (max-width:640px){
    .dashboard-kpi-grid{gap:.85rem;margin-bottom:1.1rem}
    .kpi-card{min-height:132px;border-radius:1.35rem;padding:1rem!important;box-shadow:0 14px 30px rgba(15,32,87,.13)}
    .kpi-card:hover{transform:none;box-shadow:0 14px 30px rgba(15,32,87,.13)}
    .kpi-card .kpi-icon{font-size:1.35rem}
    .kpi-card .kpi-number{font-size:2.25rem;letter-spacing:0;line-height:.95}
    .kpi-card .kpi-label{font-size:.88rem;line-height:1.05}
    .kpi-card .kpi-sub{font-size:.68rem}
    .shortcut-card{border-radius:1rem;padding:.85rem 1rem}
    .chart-card{border-radius:1.15rem;padding:1rem}
  }
</style>

<!-- ══ ROW 1: KPI ═══════════════════════════════════════════════ -->
<div class="dashboard-kpi-grid grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
<?php
$kpi_shortcuts = [
  [
    'href'  => 'simpatizantes.php',
    'val'   => $total_simpatizantes,
    'label' => 'Simpatizantes',
    'sub'   => 'Registrados',
    'icon'  => 'ti ti-users-group',
    'from'  => '#1E3A8A', 'to' => '#2D5AC8',
  ],
  [
    'href'  => 'personeros.php',
    'val'   => $total_personeros,
    'label' => 'Personeros',
    'sub'   => 'Activos',
    'icon'  => 'ti ti-id-badge-2',
    'from'  => '#6D28D9', 'to' => '#8B5CF6',
  ],
  [
    'href'  => 'credenciales-modulo.php',
    'val'   => $total_credenciales,
    'label' => 'Credenciales',
    'sub'   => 'Activas',
    'icon'  => 'ti ti-id-badge-2',
    'from'  => '#BE185D', 'to' => '#DB2777',
  ],
  [
    'href'  => 'credenciales-escaneadas.php',
    'val'   => $total_credenciales_escaneadas,
    'label' => 'Escaneadas',
    'sub'   => 'Entregadas',
    'icon'  => 'ti ti-photo-scan',
    'from'  => '#0369A1', 'to' => '#0284C7',
  ],
];
foreach($kpi_shortcuts as $k): ?>
<a href="<?= $k['href'] ?>" class="kpi-card rounded-2xl p-5 text-white shadow no-underline flex flex-col relative overflow-hidden group"
   style="background: linear-gradient(135deg, <?= $k['from'] ?>, <?= $k['to'] ?>)">

  <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full opacity-10 group-hover:opacity-20 transition-opacity"
       style="background:#fff"></div>

  <div class="flex items-center justify-between mb-3 relative z-10">
    <i class="<?= $k['icon'] ?> kpi-icon text-2xl" style="opacity:.9"></i>
    <i class="ti ti-arrow-up-right text-sm opacity-0 group-hover:opacity-70 transition-opacity"></i>
  </div>

  <p class="kpi-number text-4xl font-black leading-none counter relative z-10" data-target="<?= $k['val'] ?>">0</p>

  <div class="mt-2 relative z-10">
    <p class="kpi-label text-white text-sm font-bold leading-none"><?= $k['label'] ?></p>
    <p class="kpi-sub text-[11px] mt-0.5" style="color:rgba(255,255,255,.55)"><?= $k['sub'] ?></p>
  </div>
</a>
<?php endforeach; ?>
</div>

<!-- ══ ROW 2: SIMPATIZANTES POR SEMANA (stacked) + RANKING ═══════ -->
<div class="grid grid-cols-1 xl:grid-cols-5 gap-5 mb-5">

  <div class="chart-card xl:col-span-3">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h3 class="font-black text-[#1E3A8A] text-sm flex items-center gap-2">
          <i class="ti ti-users-group text-base"></i> Nuevos Simpatizantes
        </h3>
        <p class="text-xs text-gray-400 mt-0.5">Por semana · Últimas 8 semanas · Por distrito</p>
      </div>
      <span class="badge badge-primary text-white font-bold"><?= $total_simpatizantes ?> total</span>
    </div>
    <div id="chart-simp-semana"></div>
  </div>

  <div class="chart-card hidden md:block xl:col-span-2">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h3 class="font-black text-[#1E3A8A] text-sm flex items-center gap-2">
          <i class="ti ti-map-pin text-base"></i> Ranking Distrital
        </h3>
        <p class="text-xs text-gray-400 mt-0.5">Top distritos · acumulado</p>
      </div>
    </div>
    <div id="chart-distritos"></div>
  </div>

</div>

<!-- ══ ROW 3: PERSONEROS · CREDENCIALES ══════════════════════════ -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">

  <div class="chart-card">
    <h3 class="font-black text-[#1E3A8A] text-sm flex items-center gap-2 mb-0.5">
      <i class="ti ti-id-badge-2 text-base"></i> Personeros
    </h3>
    <p class="text-xs text-gray-400 mb-3">Nuevos por semana · 8 semanas</p>
    <div id="chart-personeros"></div>
  </div>

  <div class="chart-card">
    <h3 class="font-black text-[#1E3A8A] text-sm flex items-center gap-2 mb-0.5">
      <i class="ti ti-id-badge-2 text-base"></i> Credenciales
    </h3>
    <p class="text-xs text-gray-400 mb-3">Nuevas por semana · 8 semanas</p>
    <div id="chart-credenciales"></div>
  </div>

</div>

<!-- ══ ROW 4: ACTIVIDAD RECIENTE ══════════════════════════════════ -->
<div class="grid grid-cols-1 gap-5 mb-5">
  <div class="chart-card flex flex-col" style="padding:1rem">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-black text-[#1E3A8A] text-sm flex items-center gap-2">
        <i class="ti ti-activity text-base"></i> Actividad Reciente
      </h3>
    </div>
    <?php if (empty($activity_feed)): ?>
    <div class="flex-1 flex items-center justify-center">
      <p class="text-gray-300 text-sm text-center">Sin actividad registrada</p>
    </div>
    <?php else: ?>
    <div class="flex-1 overflow-hidden space-y-0">
      <?php
      $mod_colors = [
        'simpatizantes'=>'#1E3A8A','personeros'=>'#6D28D9',
        'credenciales_modulo'=>'#BE185D','credenciales_escaneadas'=>'#0369A1',
      ];
      foreach ($activity_feed as $idx => $log):
          $color = $mod_colors[$log['modulo']] ?? '#9CA3AF';
          $last  = $idx === count($activity_feed) - 1;
      ?>
      <div class="flex items-start gap-3 py-2.5 <?= !$last ? 'border-b border-gray-50' : '' ?>">
        <div class="flex flex-col items-center flex-shrink-0">
          <div class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0"
               style="background:<?= $color ?>18">
            <div class="w-2 h-2 rounded-full" style="background:<?= $color ?>"></div>
          </div>
          <?php if (!$last): ?><div class="w-px flex-1 bg-gray-100 my-0.5" style="min-height:12px"></div><?php endif; ?>
        </div>
        <div class="flex-1 min-w-0 pb-1">
          <p class="text-xs font-semibold text-gray-800 leading-snug truncate">
            <?= htmlspecialchars($log['accion']) ?>
          </p>
          <div class="flex items-center gap-1.5 mt-0.5">
            <span class="text-[10px] font-bold text-gray-500"><?= htmlspecialchars($log['usuario_nombre']) ?></span>
            <span class="text-gray-300">·</span>
            <span class="text-[10px] text-gray-400"><?= dash_time_ago($log['creado_en']) ?></span>
            <span class="badge badge-ghost text-[9px] h-4 px-1.5 font-semibold" style="color:<?= $color ?>;background:<?= $color ?>15">
              <?= htmlspecialchars($log['modulo'] ?? '') ?>
            </span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ APEXCHARTS ════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.49.0/dist/apexcharts.min.js"></script>
<script>
(function(){
  const weekLabels  = <?= json_encode($week_labels) ?>;
  const simpStacked = <?= json_encode($simp_stacked ?: [['name'=>'Sin datos','data'=>array_fill(0,8,0)]]) ?>;
  const persData    = <?= json_encode($pers_w_data) ?>;
  const credData    = <?= json_encode($cred_w_data) ?>;
  const distLabels  = <?= json_encode(array_reverse($dist_labels)) ?>;
  const distData    = <?= json_encode(array_reverse($dist_data)) ?>;

  const palette = ['#1E3A8A','#0F766E','#7C3AED','#B45309','#BE185D','#0369A1','#374151','#0891B2'];
  const baseChart = {
    chart:{ toolbar:{show:false}, animations:{easing:'easeinout',speed:600}, fontFamily:'Inter,sans-serif' },
    grid:{ borderColor:'#F1F5F9', strokeDashArray:4 },
    tooltip:{ theme:'light', style:{fontSize:'12px'} },
  };

  // Contador animado
  document.querySelectorAll('.counter').forEach(el=>{
    const t=parseInt(el.dataset.target,10);
    if(!t){el.textContent='0';return}
    let c=0;
    const timer=setInterval(()=>{
      c+=Math.max(1,Math.ceil(t/40));
      if(c>=t){el.textContent=t.toLocaleString();clearInterval(timer)}
      else el.textContent=c.toLocaleString();
    },900/40);
  });

  // ── Chart 1: Simpatizantes por semana (stacked bars) ───────
  new ApexCharts(document.getElementById('chart-simp-semana'), {
    ...baseChart,
    chart:{...baseChart.chart, type:'bar', height:230, stacked:true},
    series: simpStacked,
    xaxis:{ categories:weekLabels, labels:{style:{fontSize:'10px',colors:'#9CA3AF'},rotate:-30} },
    yaxis:{ labels:{style:{fontSize:'11px',colors:'#9CA3AF'}}, min:0 },
    plotOptions:{ bar:{ borderRadius:4, columnWidth:'55%' } },
    colors: palette,
    dataLabels:{ enabled:false },
    legend:{ position:'top', fontSize:'11px', fontWeight:600, markers:{radius:4} },
  }).render();

  // ── Chart 2: Distritos ranking (horizontal) ─────────────────
  new ApexCharts(document.getElementById('chart-distritos'), {
    ...baseChart,
    chart:{...baseChart.chart, type:'bar', height:230},
    plotOptions:{ bar:{ horizontal:true, borderRadius:5, barHeight:'55%',
      dataLabels:{position:'right'} } },
    series:[{ name:'Simpatizantes', data:distData }],
    xaxis:{ categories:distLabels, labels:{style:{fontSize:'10px',colors:'#9CA3AF'}} },
    yaxis:{ labels:{style:{fontSize:'10px',colors:'#374151',fontWeight:600}} },
    colors:['#1E3A8A'],
    dataLabels:{ enabled:true, style:{fontSize:'10px',colors:['#1E3A8A']}, offsetX:4 },
  }).render();

  // ── Chart 3: Personeros por semana (columnas) ───────────────
  new ApexCharts(document.getElementById('chart-personeros'), {
    ...baseChart,
    chart:{...baseChart.chart, type:'bar', height:180},
    series:[{ name:'Personeros', data:persData }],
    xaxis:{ categories:weekLabels, labels:{style:{fontSize:'9px',colors:'#9CA3AF'},rotate:-40} },
    yaxis:{ labels:{style:{fontSize:'10px',colors:'#9CA3AF'}}, min:0 },
    plotOptions:{ bar:{ borderRadius:5, columnWidth:'60%' } },
    colors:['#6D28D9'],
    dataLabels:{ enabled:false },
  }).render();

  // ── Chart 4: Credenciales por semana (columnas) ─────────────
  new ApexCharts(document.getElementById('chart-credenciales'), {
    ...baseChart,
    chart:{...baseChart.chart, type:'bar', height:180},
    series:[{ name:'Credenciales', data:credData }],
    xaxis:{ categories:weekLabels, labels:{style:{fontSize:'9px',colors:'#9CA3AF'},rotate:-40} },
    yaxis:{ labels:{style:{fontSize:'10px',colors:'#9CA3AF'}}, min:0 },
    plotOptions:{ bar:{ borderRadius:5, columnWidth:'60%' } },
    colors:['#BE185D'],
    dataLabels:{ enabled:false },
  }).render();

})();
</script>

    </main>
  </div>
</body>
</html>

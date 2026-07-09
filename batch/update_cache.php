<?php
/**
 * batch/update_cache.php
 * -----------------------------------------------------------------
 * 【GitHub Actions版】
 * 対象市区町村（config.php の TARGET_CITIES）について、直近数年分の
 * 成約価格情報を取得し、地区（DistrictName）× 種別（土地／中古戸建／
 * マンション）ごとに集計して cache/{市区町村コード}.json に保存します。
 *
 * このスクリプトは GitHub Actions のワークフロー（.github/workflows/
 * update-cache.yml）から、月1回自動実行されます。手動で実行したい場合は、
 * GitHub の「Actions」タブから該当ワークフローの「Run workflow」ボタンを
 * 押してください（サーバーやSSHは不要です）。
 *
 * APIキーは、GitHubリポジトリの Settings > Secrets and variables > Actions
 * に登録した「REINFOLIB_API_KEY」という名前のSecretから、環境変数として
 * 渡されます（このファイルやリポジトリにはAPIキーを直接書きません）。
 * -----------------------------------------------------------------
 *
 * ◆単価の計算方法について（㎡単価をメインに変更）
 * 当初は坪単価をメイン指標にしていましたが、以下の理由で「平米(㎡)単価」を
 * メイン、坪単価はそこから換算した参考値、という構成に変更しました。
 *
 *   平米単価（円/㎡） = 取引総額（円） ÷ 面積（㎡）        … メイン指標
 *   坪単価　（円/坪） = 平米単価 × 3.305785                … 参考値（1坪=3.305785㎡）
 *
 * APIが返す PricePerUnit（坪単価）や UnitPrice（平米単価）の各フィールドは
 * 空欄になるケースが多く、公開マニュアルにも単位の詳細な仕様が明記されて
 * いないため、いずれも使わず「取引総額÷面積」から自前で計算しています。
 * 土地・戸建は「面積」＝土地面積、マンションは「面積」＝専有面積を使うため、
 * どちらも同じ式で一貫して算出できます。値は常に「円」単位で保存し、
 * 画面表示側で万円などに変換します。
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/ReinfolibClient.php';

const TSUBO_IN_SQM = 3.305785; // 1坪 = 3.305785㎡
const TYPE_CATEGORIES = ['land', 'house', 'mansion']; // 'all' は別途常に集計

$apiKey = getenv('REINFOLIB_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "エラー: 環境変数 REINFOLIB_API_KEY が設定されていません。\n");
    fwrite(STDERR, "GitHub Actions の Secrets 設定、またはローカルでのテスト時は\n");
    fwrite(STDERR, "config.php 内の putenv() のコメントアウトを確認してください。\n");
    exit(1);
}

$client      = new ReinfolibClient($apiKey);
$currentYear = (int) date('Y');
$cacheDir    = __DIR__ . '/../cache';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

foreach (TARGET_CITIES as $target) {
    echo "=== {$target['name']}（{$target['city']}）===\n";

    $all = [];
    for ($y = $currentYear; $y > $currentYear - CACHE_YEARS_BACK; $y--) {
        try {
            $records = $client->getTransactions($target['pref'], $target['city'], $y, null, '02');
            echo "  {$y}年: {$target['name']} {$target['name']} 全" . count($records) . "件\n";
            $all = array_merge($all, $records);
        } catch (Throwable $e) {
            fwrite(STDERR, "  {$y}年: 取得失敗 - {$e->getMessage()}\n");
        }
        sleep(1); // 連続リクエストを避ける（マニュアル記載の注意事項に準拠）
    }

    $aggregated = aggregate_by_district($all);
    $cacheFile  = $cacheDir . '/' . $target['city'] . '.json';

    $ok = file_put_contents($cacheFile, json_encode([
        'municipality_code' => $target['city'],
        'municipality'      => $target['name'],
        'updated_at'        => date('c'),
        'raw_record_count'  => count($all),
        'districts'         => $aggregated,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($ok === false) {
        fwrite(STDERR, "  キャッシュの書き込みに失敗しました: {$cacheFile}\n");
        continue;
    }
    echo "  → " . count($aggregated) . " 地区分を保存\n\n";
}

echo "全ての対象市区町村の更新が完了しました。\n";


/**
 * 取引種別(Type)の文字列から、フロントの「土地／中古戸建／マンション」
 * のどれに該当するかを判定する。
 * 例）"宅地(土地)" → land / "宅地(土地と建物)" → house / "中古マンション等" → mansion
 */
function classify_type(?string $type): string
{
    $type = $type ?? '';
    if (mb_strpos($type, 'マンション') !== false) {
        return 'mansion';
    }
    if (mb_strpos($type, '土地と建物') !== false) {
        return 'house';
    }
    if (mb_strpos($type, '土地') !== false) {
        return 'land'; // 「宅地(土地)」など、建物を含まない土地のみの取引
    }
    return 'other'; // 農地・林地など、今回の3分類の対象外
}

/**
 * 平米単価（円/㎡）を 取引総額 ÷ 面積 で計算する。
 * 面積が0または不明な場合は null を返す。
 */
function calc_price_per_sqm_yen(?int $tradePrice, ?float $area): ?int
{
    if (!$tradePrice || !$area || $area <= 0) {
        return null;
    }
    return (int) round($tradePrice / $area);
}

/**
 * 空の集計バケットを作る（平米単価ベースで集計し、坪単価はここから換算する）
 */
function empty_bucket(): array
{
    return [
        'count'               => 0,
        'price_per_sqm_sum'   => 0,
        'price_per_sqm_count' => 0,
        'price_per_sqm_min'   => null,
        'price_per_sqm_max'   => null,
        'trade_price_sum'     => 0,
        'trade_price_count'   => 0,
        'samples'             => [],
    ];
}

/**
 * 1レコードをバケットに加算する（参照渡し）
 */
function add_to_bucket(array &$bucket, ?int $tradePrice, ?int $pricePerSqmYen, array $sample): void
{
    $bucket['count']++;

    if ($pricePerSqmYen !== null) {
        $bucket['price_per_sqm_sum'] += $pricePerSqmYen;
        $bucket['price_per_sqm_count']++;
        $bucket['price_per_sqm_min'] = $bucket['price_per_sqm_min'] === null
            ? $pricePerSqmYen : min($bucket['price_per_sqm_min'], $pricePerSqmYen);
        $bucket['price_per_sqm_max'] = $bucket['price_per_sqm_max'] === null
            ? $pricePerSqmYen : max($bucket['price_per_sqm_max'], $pricePerSqmYen);
    }
    if ($tradePrice) {
        $bucket['trade_price_sum'] += $tradePrice;
        $bucket['trade_price_count']++;
    }
    if (count($bucket['samples']) < 20) {
        $bucket['samples'][] = $sample;
    }
}

/**
 * バケットの合計値から最終的な平均等を計算する。
 * 坪単価は「平米単価の平均 × 3.305785」で換算する
 * （個々の坪単価を平均するのと数学的に同じ結果になる）。
 */
function finalize_bucket(array $bucket): array
{
    $avgSqm = $bucket['price_per_sqm_count'] > 0
        ? $bucket['price_per_sqm_sum'] / $bucket['price_per_sqm_count'] : null;

    return [
        'count'                => $bucket['count'],
        'avg_price_per_sqm'    => $avgSqm !== null ? (int) round($avgSqm) : null,
        'min_price_per_sqm'    => $bucket['price_per_sqm_min'],
        'max_price_per_sqm'    => $bucket['price_per_sqm_max'],
        'avg_price_per_tsubo'  => $avgSqm !== null ? (int) round($avgSqm * TSUBO_IN_SQM) : null,
        'min_price_per_tsubo'  => $bucket['price_per_sqm_min'] !== null ? (int) round($bucket['price_per_sqm_min'] * TSUBO_IN_SQM) : null,
        'max_price_per_tsubo'  => $bucket['price_per_sqm_max'] !== null ? (int) round($bucket['price_per_sqm_max'] * TSUBO_IN_SQM) : null,
        'avg_trade_price'      => $bucket['trade_price_count'] > 0
            ? (int) round($bucket['trade_price_sum'] / $bucket['trade_price_count']) : null,
        'samples'              => $bucket['samples'],
    ];
}

/**
 * APIレスポンス（取引レコードの配列）を
 * 地区名(DistrictName) → 種別(all/land/house/mansion) の2段階で集計する。
 */
function aggregate_by_district(array $records): array
{
    $byDistrict = [];

    foreach ($records as $r) {
        $district   = $r['DistrictName'] ?? '(地区名なし)';
        $tradePrice = is_numeric($r['TradePrice'] ?? null) ? (int) $r['TradePrice'] : null;
        $area       = is_numeric($r['Area'] ?? null) ? (float) $r['Area'] : null;
        $category   = classify_type($r['Type'] ?? null);
        $sqmYen     = calc_price_per_sqm_yen($tradePrice, $area);

        if (!isset($byDistrict[$district])) {
            $byDistrict[$district] = [
                'all' => empty_bucket(),
            ];
            foreach (TYPE_CATEGORIES as $cat) {
                $byDistrict[$district][$cat] = empty_bucket();
            }
        }

        $sample = [
            'period'          => $r['Period'] ?? null,
            'type'            => $r['Type'] ?? null,
            'trade_price'     => $tradePrice,
            'price_per_sqm'   => $sqmYen,
            'price_per_tsubo' => $sqmYen !== null ? (int) round($sqmYen * TSUBO_IN_SQM) : null,
            'area'            => $area,
            'structure'       => $r['Structure'] ?? null,
            'building_year'   => $r['BuildingYear'] ?? null,
        ];

        // 「すべて」バケットには種別を問わず全件を加算
        add_to_bucket($byDistrict[$district]['all'], $tradePrice, $sqmYen, $sample);

        // 該当する種別バケットにも加算（'other' は3分類の対象外なので加算しない）
        if (in_array($category, TYPE_CATEGORIES, true)) {
            add_to_bucket($byDistrict[$district][$category], $tradePrice, $sqmYen, $sample);
        }
    }

    $result = [];
    foreach ($byDistrict as $name => $buckets) {
        $result[$name] = [
            'all'     => finalize_bucket($buckets['all']),
            'land'    => finalize_bucket($buckets['land']),
            'house'   => finalize_bucket($buckets['house']),
            'mansion' => finalize_bucket($buckets['mansion']),
        ];
    }

    // 件数（データの厚み）が多い地区から順に並べる（'all'の件数を基準に）
    uasort($result, fn ($a, $b) => $b['all']['count'] <=> $a['all']['count']);

    return $result;
}

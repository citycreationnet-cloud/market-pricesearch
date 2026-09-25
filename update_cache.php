<?php
/**
 * batch/update_cache.php
 * -----------------------------------------------------------------
 * 【GitHub Actions版】
 * 対象市区町村（config.php の TARGET_CITIES）について、直近数年分の
 * 不動産取引価格情報・成約価格情報（両方）を取得し、地区（DistrictName）
 * × 種別（土地／中古戸建／マンション）ごとに集計して
 * cache/{市区町村コード}.json に保存します。
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
 * ◆単価の計算方法
 *   平米単価（円/㎡） = 取引総額（円） ÷ 面積（㎡）
 *   坪単価　（円/坪） = 平米単価 × 3.305785（1坪=3.305785㎡）
 * APIが返す PricePerUnit・UnitPriceの各フィールドは空欄になるケースが多く、
 * 単位の仕様も公開マニュアルに明記されていないため、いずれも使わず
 * 「取引総額÷面積」から自前で計算しています。土地・戸建は「面積」＝
 * 土地面積、マンションは「面積」＝専有面積を使うため、同じ式で一貫して
 * 算出できます。値は常に「円」単位で保存し、画面表示側で万円等に変換します。
 *
 * ◆データの種類について
 * priceClassificationを指定せず、「不動産取引価格情報」と「成約価格情報」
 * の両方を取得しています（成約価格情報だけだと、戸建・マンションしか
 * 含まれず、土地の取引が構造的に取れないため）。
 *
 * ◆外れ値除去について
 * IQR（四分位範囲）による標準的な外れ値検出を行っています。
 *   - 中古戸建：坪単価が明らかに高すぎる事例のみ除外（高い側だけ）
 *   - 土地　　：坪単価・面積の両方について、高い側・低い側とも除外
 *     （極端に狭い/広い区画が平均を歪めるのを防ぐため）
 * 同一地区・種別のサンプルが5件未満の場合は、統計的に不安定なため
 * 除外処理自体を行いません。
 *
 * ◆中央値・取引時期範囲
 * 平均だけでなく中央値（外れ値の影響を受けにくい）も算出し、
 * 集計に含まれる取引時期の範囲（最古・最新）も記録しています。
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/ReinfolibClient.php';

const TSUBO_IN_SQM = 3.305785; // 1坪 = 3.305785㎡
const TYPE_CATEGORIES = ['land', 'house', 'mansion']; // 'all' は別途常に集計
const SAMPLE_LIMIT = 50; // フロント側で築年数・面積による再絞り込みができるよう、多めに保持

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
            $records = $client->getTransactions($target['pref'], $target['city'], $y, null, '');
            echo "  {$y}年: {$target['name']} 全" . count($records) . "件\n";
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
        'updated_at'        => date('c'), // サイト（キャッシュ）の更新日時
        'raw_record_count'  => count($all),
        'years_covered'     => [$currentYear - CACHE_YEARS_BACK + 1, $currentYear], // 集計対象の年範囲
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
 * Period文字列（例："2026年第1四半期"、"2024年"）を、大小比較できる
 * 整数キーに変換する。四半期が無い場合は0四半期扱い。
 */
function period_sort_key(?string $period): int
{
    if (!$period) {
        return 0;
    }
    if (preg_match('/(\d{4})年第(\d)四半期/u', $period, $m)) {
        return ((int) $m[1]) * 10 + (int) $m[2];
    }
    if (preg_match('/(\d{4})年/u', $period, $m)) {
        return ((int) $m[1]) * 10;
    }
    return 0;
}

function empty_bucket(): array
{
    return [];
}

/**
 * 1レコードをバケットに追加する（参照渡し）。
 * 集計はここでは行わず、finalize_bucket() でまとめて計算する
 * （外れ値除去を後段でできるようにするため、生レコードのまま貯めておく）。
 */
function add_to_bucket(array &$bucket, array $sample): void
{
    $bucket[] = $sample;
}

/**
 * IQR（四分位範囲）を使って、指定したフィールドの外れ値を除外する。
 * 「Q1 - 1.5×IQR」〜「Q3 + 1.5×IQR」の範囲外を外れ値とする、
 * 統計でよく使われる標準的な外れ値検出方法。
 *
 * @param string $tails 'upper'（高い側だけ除外）または 'both'（高い側・低い側の両方を除外）
 * サンプル数が5件未満の場合は統計的に不安定なため、除外を行わない。
 */
function remove_outliers_by_field(array $records, string $field, string $tails = 'both'): array
{
    $values = array_values(array_filter(
        array_map(fn ($r) => $r[$field], $records),
        fn ($v) => $v !== null
    ));

    if (count($values) < 5) {
        return $records;
    }

    sort($values);
    $q1 = percentile($values, 25);
    $q3 = percentile($values, 75);
    $iqr = $q3 - $q1;
    $upperBound = $q3 + 1.5 * $iqr;
    $lowerBound = $q1 - 1.5 * $iqr;

    return array_values(array_filter($records, function ($r) use ($field, $tails, $upperBound, $lowerBound) {
        $v = $r[$field];
        if ($v === null) {
            return true; // 値が無いレコードは判定できないので残す
        }
        if (($tails === 'upper' || $tails === 'both') && $v > $upperBound) {
            return false;
        }
        if (($tails === 'both') && $v < $lowerBound) {
            return false;
        }
        return true;
    }));
}

/**
 * 複数フィールドについて、順番に外れ値除去を適用する。
 * 例）['price_per_sqm' => 'both', 'area' => 'both'] なら、坪単価と面積の
 * 両方について、高い側・低い側の外れ値を除外する。
 */
function remove_outliers(array $records, array $fieldTails): array
{
    foreach ($fieldTails as $field => $tails) {
        $records = remove_outliers_by_field($records, $field, $tails);
    }
    return $records;
}

/**
 * 線形補間によるパーセンタイル計算
 */
function percentile(array $sortedValues, float $pct): float
{
    $index = ($pct / 100) * (count($sortedValues) - 1);
    $lower = (int) floor($index);
    $upper = (int) ceil($index);
    if ($lower === $upper) {
        return (float) $sortedValues[$lower];
    }
    $frac = $index - $lower;
    return $sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $frac;
}

/**
 * レコード配列（1地区×1種別分）から、最終的な平均・中央値・レンジ等を計算する。
 * 坪単価は「㎡単価の平均／中央値 × 3.305785」で換算する。
 *
 * @param array $outlierFields 外れ値除去の対象フィールドと方向の指定。
 *   例）['price_per_sqm' => 'upper'] … 中古戸建：高額側のみ除外
 *       ['price_per_sqm' => 'both', 'area' => 'both'] … 土地：価格・面積とも両側除外
 *   空配列（デフォルト）の場合は外れ値除去を行わない。
 */
function finalize_bucket(array $records, array $outlierFields = []): array
{
    if (!empty($outlierFields)) {
        $records = remove_outliers($records, $outlierFields);
    }

    $sqmValues   = array_values(array_filter(array_map(fn ($r) => $r['price_per_sqm'], $records), fn ($v) => $v !== null));
    $priceValues = array_values(array_filter(array_map(fn ($r) => $r['trade_price'], $records), fn ($v) => $v !== null));

    $avgSqm = count($sqmValues) > 0 ? array_sum($sqmValues) / count($sqmValues) : null;

    $medianSqm = null;
    if (count($sqmValues) > 0) {
        $sorted = $sqmValues;
        sort($sorted);
        $medianSqm = percentile($sorted, 50);
    }

    // 取引時期の範囲（最古・最新）を調べる
    $latestPeriod = null;
    $oldestPeriod = null;
    if (!empty($records)) {
        $maxKey = -1;
        $minKey = PHP_INT_MAX;
        foreach ($records as $r) {
            $k = period_sort_key($r['period']);
            if ($k > $maxKey) {
                $maxKey = $k;
                $latestPeriod = $r['period'];
            }
            if ($k > 0 && $k < $minKey) {
                $minKey = $k;
                $oldestPeriod = $r['period'];
            }
        }
    }

    // 表示用サンプルは新しい取引順に並べ、最大 SAMPLE_LIMIT 件だけ保持する
    usort($records, fn ($a, $b) => period_sort_key($b['period']) <=> period_sort_key($a['period']));

    return [
        'count'                  => count($records),
        'avg_price_per_sqm'      => $avgSqm !== null ? (int) round($avgSqm) : null,
        'median_price_per_sqm'   => $medianSqm !== null ? (int) round($medianSqm) : null,
        'min_price_per_sqm'      => count($sqmValues) > 0 ? min($sqmValues) : null,
        'max_price_per_sqm'      => count($sqmValues) > 0 ? max($sqmValues) : null,
        'avg_price_per_tsubo'    => $avgSqm !== null ? (int) round($avgSqm * TSUBO_IN_SQM) : null,
        'median_price_per_tsubo' => $medianSqm !== null ? (int) round($medianSqm * TSUBO_IN_SQM) : null,
        'min_price_per_tsubo'    => count($sqmValues) > 0 ? (int) round(min($sqmValues) * TSUBO_IN_SQM) : null,
        'max_price_per_tsubo'    => count($sqmValues) > 0 ? (int) round(max($sqmValues) * TSUBO_IN_SQM) : null,
        'avg_trade_price'        => count($priceValues) > 0 ? (int) round(array_sum($priceValues) / count($priceValues)) : null,
        'latest_period'          => $latestPeriod,
        'oldest_period'          => $oldestPeriod,
        // 表示・再絞り込み用サンプル（新しい順、最大SAMPLE_LIMIT件）
        'samples'                => array_slice($records, 0, SAMPLE_LIMIT),
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
            'period'           => $r['Period'] ?? null,
            'type'             => $r['Type'] ?? null,
            'trade_price'      => $tradePrice,
            'price_per_sqm'    => $sqmYen,
            'price_per_tsubo'  => $sqmYen !== null ? (int) round($sqmYen * TSUBO_IN_SQM) : null,
            'area'             => $area,
            'total_floor_area' => is_numeric($r['TotalFloorArea'] ?? null) ? (float) $r['TotalFloorArea'] : null,
            'structure'        => $r['Structure'] ?? null,
            'building_year'    => $r['BuildingYear'] ?? null,
        ];

        // 「すべて」バケットには種別を問わず全件を加算
        add_to_bucket($byDistrict[$district]['all'], $sample);

        // 該当する種別バケットにも加算（'other' は3分類の対象外なので加算しない）
        if (in_array($category, TYPE_CATEGORIES, true)) {
            add_to_bucket($byDistrict[$district][$category], $sample);
        }
    }

    $result = [];
    foreach ($byDistrict as $name => $buckets) {
        $result[$name] = [
            'all'     => finalize_bucket($buckets['all']),
            'land'    => finalize_bucket($buckets['land'], ['price_per_sqm' => 'both', 'area' => 'both']),
            'house'   => finalize_bucket($buckets['house'], ['price_per_sqm' => 'upper']),
            'mansion' => finalize_bucket($buckets['mansion']),
        ];
    }

    // 件数（データの厚み）が多い地区から順に並べる（'all'の件数を基準に）
    uasort($result, fn ($a, $b) => $b['all']['count'] <=> $a['all']['count']);

    return $result;
}

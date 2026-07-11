<?php
/**
 * ReinfolibClient.php
 * 不動産情報ライブラリ（国土交通省）公開APIの薄いラッパークラス。
 *
 * 重要：不動産情報ライブラリの利用マニュアルには「CORSエラー防止のため、
 * APIリクエストをブラウザから送信しないように」との注意書きがあります。
 * そのため、このクラスは必ずサーバーサイド（PHP側）からのみ呼び出してください。
 * ブラウザの JavaScript から直接 fetch() してはいけません。
 *
 * 公式マニュアル: https://www.reinfolib.mlit.go.jp/help/apiManual/
 */

class ReinfolibClient
{
    private string $apiKey;
    private string $baseUrl = 'https://www.reinfolib.mlit.go.jp/ex-api/external/';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @throws RuntimeException
     */
    private function request(string $endpoint, array $params): array
    {
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Ocp-Apim-Subscription-Key: {$this->apiKey}"],
            CURLOPT_ENCODING       => '', // gzip応答を自動で解凍させる
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("通信エラー: {$err}");
        }

        // マニュアルに記載の通り、対象データが無い場合は404が返る
        if ($status === 404) {
            return [];
        }
        if ($status !== 200) {
            throw new RuntimeException("API がエラーを返しました (HTTP {$status}): " . substr((string)$body, 0, 300));
        }

        $data = json_decode((string) $body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSONの解析に失敗しました: ' . json_last_error_msg());
        }

        return $data ?? [];
    }

    /**
     * XIT002: 都道府県内市区町村一覧取得API
     * 市区町村コードが分からないときに使う補助メソッド。
     */
    public function getMunicipalities(string $prefCode): array
    {
        $result = $this->request('XIT002', ['area' => $prefCode]);
        return $result['data'] ?? [];
    }

    /**
     * XIT001: 不動産価格（取引価格・成約価格）情報取得API
     *
     * @param string   $prefCode             都道府県コード（2桁）
     * @param string   $cityCode             市区町村コード（5桁）
     * @param int      $year                 取引時期（年, 例: 2025）
     * @param int|null $quarter              取引時期（四半期 1-4）。nullなら年間全体
     * @param string   $priceClassification  '01'=取引価格のみ '02'=成約価格のみ ''=両方
     *                                       成約事例をベースにしたいので既定は '02'
     */
    public function getTransactions(
        string $prefCode,
        string $cityCode,
        int $year,
        ?int $quarter = null,
        string $priceClassification = '02'
    ): array {
        $params = [
            'area' => $prefCode,
            'city' => $cityCode,
            'year' => $year,
        ];
        if ($priceClassification !== '') {
            $params['priceClassification'] = $priceClassification;
        }
        if ($quarter !== null) {
            $params['quarter'] = $quarter;
        }

        $result = $this->request('XIT001', $params);
        return $result['data'] ?? [];
    }
}

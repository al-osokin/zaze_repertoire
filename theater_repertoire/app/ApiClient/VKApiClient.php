<?php

class VKApiClient
{
    private const API_BASE_URL = 'https://api.vk.com/method/';

    private string $accessToken;
    private string $version;

    public function __construct(string $accessToken, string $version = '5.131')
    {
        $this->accessToken = $accessToken;
        $this->version = $version;
    }

    private function executeRequest(string $method, array $params = [], bool $usePost = false): array|int|null
    {
        $params['access_token'] = $this->accessToken;
        $params['v'] = $this->version;

        $url = self::API_BASE_URL . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($usePost) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            $url .= '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $errorMessage = curl_error($ch);
            curl_close($ch);
            error_log('VK API request failed: ' . $errorMessage);
            return null;
        }

        curl_close($ch);

        $decodedResponse = json_decode($response, true);

        if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log('VK API response decode error: ' . json_last_error_msg());
            return null;
        }

        if (isset($decodedResponse['error'])) {
            // Handle API error, e.g., log it
            // For simplicity, we'll just return it for now
            return $decodedResponse;
        }

        return $decodedResponse['response'] ?? $decodedResponse;
    }

    /**
     * Returns a list of wiki pages from a group.
     *
     * @param int $groupId
     * @return array|null
     */
    public function getPagesList(int $groupId): ?array
    {
        return $this->executeRequest('pages.getTitles', ['group_id' => $groupId]);
    }

    /**
     * Returns the text of a wiki page.
     *
     * @param int $pageId
     * @param int $groupId
     * @param bool $needSource
     * @return array|int|null
     */
    public function getPage(int $pageId, int $groupId, bool $needSource = false): array|int|null
    {
        $params = ['owner_id' => -$groupId, 'page_id' => $pageId];
        if ($needSource) {
            $params['need_source'] = 1;
        }

        return $this->executeRequest('pages.get', $params);
    }

    /**
     * Returns a wiki page by its title.
     *
     * @param string $title
     * @param int $groupId
     * @param bool $needSource
     * @param bool $needHtml
     * @return array|int|null
     */
    public function getPageByTitle(string $title, int $groupId, bool $needSource = false, bool $needHtml = false): array|int|null
    {
        $params = [
            'owner_id' => -$groupId,
            'title' => $title,
        ];

        if ($needSource) {
            $params['need_source'] = 1;
        }
        if ($needHtml) {
            $params['need_html'] = 1;
        }

        return $this->executeRequest('pages.get', $params);
    }

    /**
     * Saves the text of a wiki page.
     *
     * @param string $title
     * @param string $text
     * @param int $groupId
     * @param int|null $pageId
     * @return array|null
     */
    public function savePage(string $title, string $text, int $groupId, ?int $pageId = null): array|int|null
    {
        $params = [
            'title' => $title,
            'text' => $text,
            'group_id' => $groupId,
        ];

        if ($pageId) {
            $params['page_id'] = $pageId;
        }

        return $this->executeRequest('pages.save', $params, true);
    }
}

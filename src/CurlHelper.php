<?php namespace m8rge;

class CurlHelper
{
    protected static function defaultSettings()
    {
        return [
            CURLOPT_AUTOREFERER => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
    }

    /**
     * @param string $url
     * @param array $additionalConfig
     * @param int $retryCount
     * @throws CurlException
     * @return CurlResult
     */
    public static function getUrlFailSafe($url, $additionalConfig = [], $retryCount = 5)
    {
        for ($i = 0; $i < $retryCount; $i++) {
            try {
                return self::getUrl($url, $additionalConfig);
            } catch (CurlException $e) {
                sleep($i);
                if ($e->curlResult->statusCode < 500 || $i + 1 == $retryCount) {
                    throw $e;
                }
            }
        }

        return false;
    }

    /**
     * @param string $url
     * @param array $additionalConfig
     * @throws CurlException
     * @return CurlResult
     */
    public static function getUrl($url, $additionalConfig = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HEADER => 1,
            ] + $additionalConfig + self::defaultSettings());
        curl_exec($ch);
        $result = new CurlResult($ch);
        curl_close($ch);

        if ($result->error || $result->statusCode >= 400) {
            throw new CurlException($result);
        }

        return $result;
    }

    /**
     * @param string $url
     * @param array $postFields
     * @param array $additionalConfig
     * @return CurlResult
     * @throws CurlException
     */
    public static function postUrl($url, $postFields, $additionalConfig = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HEADER => 1,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => $postFields,
            ] + $additionalConfig + self::defaultSettings());
        curl_exec($ch);
        $result = new CurlResult($ch);
        curl_close($ch);

        if ($result->error || $result->statusCode >= 400) {
            throw new CurlException($result);
        }

        return $result;
    }

    /**
     * @param string $url
     * @param string $toFile file name
     * @param array $additionalConfig
     * @throws CurlException
     */
    public static function downloadToFile($url, $toFile, $additionalConfig = [])
    {
        $fp = fopen($toFile, 'w');
        $ch = curl_init();
        curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FILE => $fp,
            ] + $additionalConfig + self::defaultSettings());
        curl_exec($ch);
        $result = new CurlResult($ch);
        curl_close($ch);
        fclose($fp);

        if ($result->error || $result->statusCode >= 400) {
            throw new CurlException($result);
        }
    }

    /**
     * @param array $urlsToFiles
     * @param callable $callback function(CurlResult $result, CurlException|null $e)
     * @param array $additionalConfig
     * @param int $parallelDownloads
     * @throws \Exception
     */
    public static function batchDownload($urlsToFiles, $callback, $additionalConfig = [], $parallelDownloads = 5)
    {
        $selectTimeout = 1;
        $options = $additionalConfig + self::defaultSettings();
        $requests = [];

        $master = curl_multi_init();

        /**
         * @param string $url
         * @param string $toFile
         * @throws \Exception
         */
        $addRequest = function ($url, $toFile) use ($options, $master, &$requests) {
            $fp = fopen($toFile, 'w');

            $ch = curl_init();
            curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_FILE => $fp,
                ] + $options);
            if (CURLM_OK != $res = curl_multi_add_handle($master, $ch)) {
                throw new \Exception("error($res) while adding curl multi handle");
            }
            $requests[(int)$ch] = [
                'url' => $url,
                'filePointer' => $fp,
                'fileName' => $toFile,
            ];
        };

        $i = 0;
        foreach (array_slice($urlsToFiles, $i, $parallelDownloads, true) as $url => $toFile) {
            $addRequest($url, $toFile);
            $i++;
        }

        do {
            while (CURLM_CALL_MULTI_PERFORM == $res = curl_multi_exec($master, $running)) {
            }
            if ($res != CURLM_OK) {
                throw new \Exception("curl_multi_exec failed with error code " . $res);
            }

            while ($done = curl_multi_info_read($master)) {
                $e = null;
                $ch = $done['handle'];
                $request = $requests[(int)$ch];
                $result = new CurlResult($ch);
                if ($result->error || $result->statusCode >= 400) {
                    $e = new CurlException($result);
                }

                fclose($request['filePointer']);
                call_user_func($callback, $result, $e);

                if ($i < count($urlsToFiles)) {
                    $entry = array_slice($urlsToFiles, $i++, 1, true);
                    $addRequest(key($entry), reset($entry));
                    $running = true;
                }

                curl_multi_remove_handle($master, $ch);
                curl_close($ch);
            }
            if ($running) {
                curl_multi_select($master, $selectTimeout);
            }
        } while ($running);

        curl_multi_close($master);
    }

    /**
     * @param string[] $urls
     * @param callable $callback function(CurlResult $result, CurlException|null $e)
     * @param array $additionalConfig
     * @param int $parallelDownloads
     * @throws \Exception
     */
    public static function batchGet($urls, $callback, $additionalConfig = [], $parallelDownloads = 5)
    {
        $selectTimeout = 1;
        $options = $additionalConfig + self::defaultSettings();

        $master = curl_multi_init();

        /**
         * @param string $url
         * @throws \Exception
         */
        $addRequest = function ($url) use ($options, $master) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => 1,
                ] + $options);
            if (CURLM_OK != $res = curl_multi_add_handle($master, $ch)) {
                throw new \Exception("error($res) while adding curl multi handle");
            }
        };

        $i = 0;
        foreach (array_slice($urls, $i, $parallelDownloads) as $url) {
            $addRequest($url);
            $i++;
        }

        do {
            while (CURLM_CALL_MULTI_PERFORM == $res = curl_multi_exec($master, $running)) {
            }
            if ($res != CURLM_OK) {
                throw new \Exception("curl_multi_exec failed with error code " . $res);
            }

            while ($done = curl_multi_info_read($master)) {
                $e = null;
                $ch = $done['handle'];
                $result = new CurlResult($ch);
                if ($result->error || $result->statusCode >= 400) {
                    $e = new CurlException($result);
                }

                call_user_func($callback, $result, $e);

                foreach (array_slice($urls, $i, 1) as $url) {
                    $addRequest($url);
                    $i++;
                    $running = true;
                }

                curl_multi_remove_handle($master, $ch);
                curl_close($ch);
            }
            if ($running) {
                curl_multi_select($master, $selectTimeout);
            }
        } while ($running);

        curl_multi_close($master);
    }
}

<?php

namespace cryodrift\ai;

use cryodrift\fw\cli\CliUi;
use cryodrift\fw\cli\Colors;
use cryodrift\fw\cli\ParamFile;
use cryodrift\fw\cli\ParamFiles;
use cryodrift\fw\Config;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\interface\Handler;
use cryodrift\fw\trait\CliHandler;

class Cli implements Handler
{
    use CliHandler;

    const array REMOVE = ['/batches', '/responses', '/chat/completions', '/files'];

    public function __construct(
      private string $agent,
      private readonly string $datadir,
    ) {
    }

    public function handle(Context $ctx): Context
    {
        return $this->handleCli($ctx);
    }


    /**
     * @cli Responses
     * @cli this is more expensive then batch
     *
     */
    protected function resp(Context $ctx, ParamProvider $provider, ParamModel $model, ParamFiles $content, string $outfile = '', string $id = '', bool $send = false): string
    {
        $out = '';
        $data = Core::iterate($content, fn(ParamFile $file) => $file->value);

        $payload = self::getPayload($provider->value->payload, $model->value, PHP_EOL . implode(PHP_EOL, $data));
        if ($id) {
            $payload['previous_response_id'] = $id;
        }
        //        Core::echo(__METHOD__, $payload);
        $out .= Core::toLog('Payload:', $payload);
        $json = self::fetch($provider->value, $payload, $send, agent: $this->agent);
        if ($send) {
            $data = Core::catch(fn() => Core::jsonRead($json));
            $filename = md5($out);
            Core::fileWrite($this->datadir . $filename . '_in.chat', $out);
            $msg = Core::toLog(Core::extractKeys($data, ['content']));
            Core::fileWrite($this->datadir . $filename . '_msg.chat', $msg);
            Core::fileWrite($this->datadir . $filename . '_all.chat', $json);
            $out = '';
            if ($outfile) {
                $status = $this->extract(new ParamFile($ctx, 'msg', $msg, true), $outfile);
                if (!str_starts_with($status, 'Done')) {
                    $out = $msg;
                }
            }
            $out .= Core::toLog('File:', $this->datadir . $filename, 'ID:', Core::getValue('id', $data));
        } else {
            $out .= $json;
        }
        return $out;
    }

    /**
     * @cli extract html from message
     */
    protected function extract(ParamFile $msg, string $outfile): string
    {
        $out = self::getHtml($msg->value);
        if ($out) {
            Core::fileWrite($outfile, $out);
            return 'Done' . PHP_EOL;
        }
        return 'NO HTML FOUND' . PHP_EOL;
    }

    /**
     * @cli show available models for Provider
     */
    protected function models(ParamProvider $provider, bool $send = false): string
    {
        $provider->value->url = $provider->value->modelsurl;
        if ($send) {
            $out = self::fetchModels($provider->value, $this->agent);
            return CliUi::arrayToCli($out);
        } else {
            return CliUi::arrayToCli(['url' => $provider->value->url]);
        }
    }

    /**
     * @cli run a batchjob, run again to ping, run again to download results
     */
    protected function batch(Context $ctx, ParamFiles $content, ParamModel $model, ParamProvider $provider, string $outfile = '', string $id = '', bool $send = false, bool $stop = false): string
    {
        $out = '';

        $data = Core::iterate($content, fn(ParamFile $file) => $file->value);
        $dataString = implode(PHP_EOL, $data);
        $hash = md5($dataString);
        if ($id) {
            $hash = $id;
        }
        $stateFile = $this->datadir . $hash . '.batch';

        if ($state = Core::catch(fn() => self::getBatchState($this->datadir . $hash), false)) {
            switch ($state['status']) {
                case 'completed':
                    if ($send) {
                        $uploadUrl = str_replace(self::REMOVE, '/files', $provider->value->url);
                        $provider->value->url = $uploadUrl . '/' . $state['output_file_id'] . '/content';
                        $json = self::fetch($provider->value, [], $send, post: false, agent: $this->agent);
                        if ($outfile) {
                            $data = self::extractFile($json);
                            $data = Core::toLog($data);
                            if (str_ends_with($outfile, '.html')) {
                                $out = self::getHtml($data);
                                if ($out) {
                                    $data = $out;
                                }
                            }
                            Core::fileWrite($outfile, $data);
                            return 'Done: ' . $outfile . PHP_EOL;
                        }
                        return $json;
                    }
                    return Core::toLog('Batch completed. File ID: ', $state, '. Run with -send to download.');
                    break;
                case 'failed':
                    return Core::toLog('Batch Failed: ', $state);
                    break;
                default:
                    $batchUrl = str_replace(self::REMOVE, '/batches', $provider->value->url);
                    if ($stop) {
                        $provider->value->url = $batchUrl . '/' . $state['id'] . '/cancel';
                        $json = self::fetch($provider->value, [], $send, agent: $this->agent);
                        $cancelInfo = Core::jsonRead($json);
                        if (isset($cancelInfo['error'])) {
                            return Core::toLog('Batch cancellation failed: ', $cancelInfo);
                        }
                        $state['status'] = $cancelInfo['status'] ?? 'cancelling';
                        if ($send) {
                            Core::fileWrite($stateFile, Core::jsonWrite($state));
                        }
                        return Core::toLog('Batch cancellation requested: ', $cancelInfo);
                    }
                    $provider->value->url = $batchUrl . '/' . $state['id'];
                    $json = self::fetch($provider->value, [], $send, post: false, agent: $this->agent);
                    $batchInfo = Core::jsonRead($json);
                    switch (Core::getValue('status', $batchInfo)) {
                        case 'completed':
                            $state['status'] = 'completed';
                            $state['output_file_id'] = $batchInfo['output_file_id'] ?? '';
                            Core::fileWrite($stateFile, Core::jsonWrite($state));
                            return Core::toLog('Batch finished! Run again to download.');
                            break;
                        case 'failed':
                            $state['status'] = 'failed';
                            $state['result'] = $batchInfo;
                            Core::fileWrite($stateFile, Core::jsonWrite($state));
                            return Core::toLog('Batch Failed!', $state);
                            break;
                        default:
                            return Core::toLog(Colors::get('Batch status: ', Colors::FG_light_green), $batchInfo['status'], $batchInfo);
                    }
            }
        }


        // 1. Upload file
        $uploadParams = self::createFileUpload($dataString, $provider->value, $model->value);
        $uploadUrl = str_replace(self::REMOVE, '/files', $provider->value->url);
        $batchUrl = str_replace(self::REMOVE, '/batches', $provider->value->url);

        $result = Core::fileReadOnce($uploadUrl, true, $uploadParams);

        $uploadResp = Core::jsonRead($result);
        Core::echo(__METHOD__, $uploadParams, $uploadResp);
        $fileId = $uploadResp['id'] ?? throw new \Exception('Upload failed: ' . $result);

        // 2. Create batch
        $batchPayload = [
          $provider->value->inputkey => $fileId,
          'endpoint' => parse_url($provider->value->url, PHP_URL_PATH),
          'completion_window' => '24h'
        ];
        $provider->value->url = $batchUrl;
        if (!$send) {
            Core::echo(__METHOD__, self::deleteFile($provider->value, $fileId));
        }
        $batchResp = Core::jsonRead(self::fetch($provider->value, $batchPayload, $send));

        if ($send) {
            $batchId = $batchResp['id'] ?? throw new \Exception('Batch creation failed: ' . Core::jsonWrite($batchResp));
            Core::fileWrite($stateFile, Core::jsonWrite([
              'id' => $batchId,
              'file_id' => $fileId,
              'status' => 'created',
              'provider' => $provider->value
            ]));

            return Core::toLog('Batch created: ', $batchResp, 'id:', $hash);
        }

        return Core::toLog('Ready to send batch request. Use -send to start.', $batchResp);
    }

    /**
     * @cli show list of batches
     */
    protected function batches(string $id = '', bool $delete = false, bool $status = false): string
    {
        $out = '';
        if ($id) {
            if ($state = Core::catch(fn() => self::getBatchState($this->datadir . $id), false)) {
                $out .= Core::toLog($state);
                if ($status) {
                    $provider = new Provider(...$state['provider']);
                    $batchUrl = str_replace(self::REMOVE, '/batches', $provider->url);
                    $provider->url = $batchUrl . '/' . $state['id'];
                    $json = self::fetch($provider, [], true, post: false, agent: $this->agent);
                    $batchInfo = Core::jsonRead($json);
                    $out .= Core::toLog($batchInfo);
                }
                if ($delete) {
                    $out .= Core::toLog('Delete:', self::deleteFile(new Provider(...$state['provider']), $state['file_id']));
                    self::delBatchState($this->datadir . $id);
                }
            }
        }
        $out .= CliUi::arrayToCli(Core::iterate(Core::dirList($this->datadir, fn(\SplFileInfo $f) => $f->getExtension() === 'batch'), function (\SplFileInfo $f) {
            return [Core::shift(explode('.' . $f->getExtension(), $f->getBasename())) => date('Y-m-d H:i:s', $f->getCTime())];
        }));
        return $out;
    }

    /**
     * @cli list and delete files
     */
    protected function files(ParamProvider $provider, string $id = '', string $outfile = '', bool $delete = false, bool $read = false): string
    {
        $baseUrl = str_replace(self::REMOVE, '', $provider->value->url);
        $filesUrl = $baseUrl . '/files';
        if ($id) {
            if ($delete) {
                return self::deleteFile($provider->value, $id);
            }
            if ($read) {
                $provider->value->url = $filesUrl . '/' . $id;
                $res = Core::jsonRead(self::fetch($provider->value, [], send: true, post: false, agent: $this->agent));
//                Core::echo(__METHOD__, $res);
                $provider->value->url = $filesUrl . '/' . $id . '/content';
                $res = self::fetch($provider->value, [], send: true, post: false, agent: $this->agent);
                $data = self::extractFile($res);
                $filename = $outfile ?: $this->datadir . $id . '.json';
                $data = Core::toLog($data);
                if (str_ends_with($outfile, '.html')) {
                    $data = self::getHtml($data) ?: $data;
                }
                Core::fileWrite($filename, $data);
                return Core::toLog('File written to: ', $filename);
            }
        }

        $provider->value->url = $filesUrl;
        $out = self::fetch($provider->value, [], send: true, post: false, agent: $this->agent);

        return $out;
    }

    public static function extractFile(string $json): array
    {
        return Core::iterate(explode("\n", $json), function ($line) {
            if (trim($line)) {
                return Core::jsonRead($line);
            }
        });
    }

    public static function fetchModels(Provider $provider, string $agent): array
    {
        return Core::jsonRead(self::fetch($provider, [], post: false, send: true, agent: $agent));
    }

    public static function getPayload(string $name, string $model, string $content): array
    {
        return match ($name) {
            'hug' => [
              'messages' => [
                [
                  'role' => 'user',
                  'content' => $content
                ]
              ],
              'model' => $model,
              'store' => false,
            ],
            'oai' => [
              'model' => $model,
              'input' => $content
            ]
        };
    }

    public static function fetch(Provider $provider, array $payload, bool $send = false, bool $post = true, string $agent = ''): string
    {
        $url = $provider->url;
        $headers = self::getHeaders($provider->bearer, $provider->headers);
        $params = [
          'http' => [
            'method' => $post ? 'POST' : 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => -1,
            'ignore_errors' => true,
            'user_agent' => $agent,
            'protocol_version' => 1.1,
            'follow_location' => 1,
            'max_redirects' => 10,
          ],
        ];
        if ($post) {
            $params['http']['content'] = Core::jsonWrite($payload);
        }
        if ($send) {
            return Core::fileReadOnce($url, false, $params);
        } else {
            return Core::jsonWrite([__METHOD__, $url, $params]);
        }
    }

    public static function getHeaders(string $bearer, array $headers = []): array
    {
        return [
          'Authorization: Bearer ' . $bearer,
          'Content-Type: application/json',
          ...Core::iterate($headers, fn($v, $k) => $k . ': ' . $v)
        ];
    }

    public static function getHtml(string $text): string
    {
        // Line-by-line extraction without regex
        $lines = preg_split("/\R/", $text);

        $collect = false;
        $buffer = '';

        foreach ($lines as $line) {
            // Start collecting
            if (!$collect && stripos($line, '<html ') !== false) {
                $collect = true;
            }

            // Collect lines
            if ($collect) {
                $buffer .= $line . "\n";
            }

            // Stop collecting
            if ($collect && stripos($line, '</html>') !== false) {
                break;
            }
        }
        if (str_contains($buffer, '</html>')) {
            return $buffer;
        } else {
            return '';
        }
    }

    private static function createFileUpload(string $content, Provider $provider, string $model): array
    {
        $jsonl = Core::jsonWrite([
            'custom_id' => 'req_1',
            'method' => 'POST',
            'url' => parse_url($provider->url, PHP_URL_PATH),
            'body' => [
              'model' => $model,
              'input' => $content
//                  'messages' => [['role' => 'user', 'content' => $file->value]]
            ]
          ], 0) . "\n";

        $boundary = '--------------------------' . microtime(true);
        $multipart = "--$boundary\r\n";
        $multipart .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
        $multipart .= "batch\r\n";
        $multipart .= "--$boundary\r\n";
        $multipart .= "Content-Disposition: form-data; name=\"file\"; filename=\"batch.jsonl\"\r\n";
        $multipart .= "Content-Type: application/octet-stream\r\n\r\n";
        $multipart .= $jsonl;
        $multipart .= "\r\n--$boundary--\r\n";

        $uploadHeaders = [
          'Authorization: Bearer ' . $provider->bearer,
          'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];
        $uploadParams = [
          'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $uploadHeaders) . "\r\n",
            'content' => $multipart,
            'ignore_errors' => true,
          ]
        ];
        return $uploadParams;
    }

    public static function deleteFile(Provider $provider, string $fileid): string
    {
        $url = str_replace(self::REMOVE, '/files', $provider->url) . '/' . $fileid;
        $headers = self::getHeaders($provider->bearer, $provider->headers);
        $params = [
          'http' => [
            'method' => 'DELETE',
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
          ],
        ];
        return Core::fileReadOnce($url, false, $params);
    }

    public static function getBatchState(string $path): array
    {
        $stateFile = $path . '.batch';
        return Core::jsonRead(Core::fileReadOnce($stateFile));
    }

    public static function delBatchState(string $path): void
    {
        $stateFile = $path . '.batch';
        unlink($stateFile);
    }

}

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
    protected function resp(Context $ctx, ParamProvider $provider, ParamModel $model, ParamFiles $content, string $fileid = '', string $outfile = '', string $id = '', bool $send = false): string
    {
        $out = '';
        $data = Core::iterate($content, fn(ParamFile $file) => $file->value);

        $payload = self::getPayload($provider->value->payload, $model->value, PHP_EOL . implode(PHP_EOL, $data));
        if ($id) {
            $payload['previous_response_id'] = $id;
        }
        if ($fileid) {
            $textcontent = $payload['input'];
            $payload['input'] = [
              [
                'role' => 'user',
                'content' => [
                  ['type' => 'input_text', 'text' => $textcontent],
                  ['type' => 'input_file', 'file_id' => $fileid],
                ]
              ]
            ];
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
                if (str_ends_with($outfile, 'html')) {
                    $status = $this->extract(new ParamFile($ctx, 'msg', $msg, true), $outfile);
                    if (!str_starts_with($status, 'Done')) {
                        $out = $msg;
                    }
                } else {
                    $parts = Core::extractKeys($data, ['content']);
                    $parts = Core::extractKeys($parts, ['text']);
                    Core::fileWrite($outfile, Core::getValue('text', $parts));
                }
            }
            $out .= Core::toLog('File:', $this->datadir . $filename, 'ID:', Core::getValue('id', $data));
        } else {
            $out .= $json;
        }
        return $out;
    }

    /**
     * @cli führt Phasen aus
     */
    protected function phases(Context $ctx, ParamFile $prompts, string $outfile, int $phase, ParamModel $model, ParamProvider $provider, bool $send = false): string
    {
        $out = '';
        $out = Core::toLog('phases', explode('###;', $prompts->value));
        return $out;
    }

    /**
     * @cli run a batchjob, run again to ping, run again to download results
     */
    protected function batch(ParamFiles $content, ParamModel $model, ParamProvider $provider, string $outfile = '', string $id = '', bool $send = false, bool $stop = false): string
    {
        $out = '';

        $data = Core::iterate($content, fn(ParamFile $file) => $file->value);
        $dataString = implode(PHP_EOL, $data);
        $hash = md5($dataString . $model->value . $provider->value->url);

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
                            $data = Core::catch(fn() => self::extractFile($json), false);
                            if ($data) {
                                if (str_ends_with($outfile, '.html')) {
                                    $out = self::getHtml(Core::toLog($data));
                                    if ($out) {
                                        $data = $out;
                                    } else {
                                        $data = Core::toLog($data);
                                    }
                                } elseif (str_ends_with($outfile, '.text')) {
                                    $data = Core::extractKeys($data, ['content']);
                                    $data = Core::extractKeys($data, ['text']);
                                    $data = Core::getValue('text', $data);
                                    Core::echo(__METHOD__, $data);
                                }
                            } else {
                                return Core::toLog(Colors::get('FAILED OUTFILE', Colors::FG_light_red), $json);
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
                            $counts = Core::getValue('request_counts', $batchInfo);
                            if (Core::getValue('completed', $counts)) {
                                $state['status'] = 'completed';
                                $state['output_file_id'] = $batchInfo['output_file_id'] ?? '';
                                $state['ended'] = date('Y-m-d H:i:s');
                                $status = 'Batch finished! Run again to download.';
                            } else {
                                $state['status'] = 'failed';
                                $state['result'] = $batchInfo;
                                $state['output_file_id'] = $batchInfo['output_file_id'] ?? '';
                                $state['ended'] = date('Y-m-d H:i:s');
                                $status = 'Batch Failed.';
                            }
                            break;
                        case 'failed':
                            $state['status'] = 'failed';
                            $state['result'] = $batchInfo;
                            $state['ended'] = date('Y-m-d H:i:s');
                            $status = 'Batch Failed!';
                            break;
                        default:
                            $batchInfo['created_at'] = date('Y-m-d H:i:s', $batchInfo['created_at']);
                            $batchInfo['in_progress_at'] = date('Y-m-d H:i:s', $batchInfo['in_progress_at']);
                            $batchInfo['expires_at'] = date('Y-m-d H:i:s', $batchInfo['expires_at']);
                            $status = 'Batch status: ';
                    }
                    Core::catch(fn() => Core::fileWrite($stateFile, Core::jsonWrite($state)));
                    return Core::toLog(Colors::get($status, Colors::FG_light_green), $batchInfo['status'], $batchInfo, $state);
            }
        }


        // 1. Upload file
        $uploadParams = self::createFileUpload($dataString, $provider->value, $model->value);
        $uploadUrl = str_replace(self::REMOVE, '/files', $provider->value->url);
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

        $batchUrl = str_replace(self::REMOVE, '/batches', $provider->value->url);
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
              'provider' => $provider->value,
              'model' => $model->value,
              'started' => date('Y-m-d H:i:s')
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

        $data = iterator_to_array(Core::dirList($this->datadir, fn(\SplFileInfo $f) => $f->getExtension() === 'batch'));
        uasort($data, function (\SplFileInfo $a, \SplFileInfo $b) {
            return $a->getCTime() <=> $b->getCTime();
        });
        $out .= CliUi::arrayToCli(Core::iterate($data, function (\SplFileInfo $f) {
            return [Core::shift(explode('.' . $f->getExtension(), $f->getBasename())) => date('Y-m-d H:i:s', $f->getCTime())];
        }));
        return $out;
    }

    /**
     * @cli list and delete files
     */
    protected function files(ParamProvider $provider, string $id = '', string $outfile = '', bool $delete = false, bool $read = false, bool $send = false, ?ParamFile $upload = null): string
    {
        $baseUrl = str_replace(self::REMOVE, '', $provider->value->url);
        $filesUrl = $baseUrl . '/files';
        if ($upload) {
            $uploadParams = self::createFileUpload($upload->value, $provider->value, basename($upload->filename), true);
            $uploadUrl = str_replace(self::REMOVE, '/files', $provider->value->url);
            if ($send) {
                $result = Core::fileReadOnce($uploadUrl, true, $uploadParams);
                $uploadResp = Core::jsonRead($result);
                Core::echo(__METHOD__, $uploadParams, $uploadResp);
                $fileId = $uploadResp['id'] ?? throw new \Exception('Upload failed: ' . $result);
                return Core::toLog('FileId:', $fileId);
            }
            return Core::toLog('Uploaddata:', $uploadParams, $uploadUrl);
        }
        if ($id) {
            if ($delete) {
                return self::deleteFile($provider->value, $id);
            }
            if ($read) {
                $provider->value->url = $filesUrl . '/' . $id;

                $fileinfo = Core::jsonRead(self::fetch($provider->value, [], send: true, post: false, agent: $this->agent));

                $provider->value->url = $filesUrl . '/' . $id . '/content';
                $res = self::fetch($provider->value, [], send: true, post: false, agent: $this->agent);
                $data = Core::catch(fn() => self::extractFile($res), false);
                if ($data) {
                    $filename = $outfile ?: $this->datadir . $id . '.json';
                    $data = Core::toLog($data);
                    if (str_ends_with($outfile, '.html')) {
                        $data = self::getHtml($data) ?: $data;
                    }
                } else {
//                    Core::echo(__METHOD__, $fileinfo);
                    $filename = $outfile ?: $this->datadir . $id . '_' . $fileinfo['filename'];
                    $data = $res;
                }

                Core::fileWrite($filename, $data);
                return Core::toLog('File written to: ', $filename);
            }
        }

        $provider->value->url = $filesUrl;
        $files = Core::jsonRead(self::fetch($provider->value, [], send: true, post: false, agent: $this->agent));
        uasort($files['data'], function (array $a, array $b) {
            return $a['purpose'] <=> $b['purpose'];
        });
        $files = Core::iterate($files['data'], function (array $info) {
            $info['created_at'] = date('Y-m-d H:i:s', $info['created_at']);
            return [$info['purpose'] => Core::extractKeys($info, ['id', 'filename', 'status', 'created_at'])];
        });

        $out = Core::toLog(CliUi::arrayToCli($files));

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
    protected function models(Cache $cache, ParamProvider $provider, bool $send = false): string
    {
        $provider->value->url = $provider->value->modelsurl;
        if ($send) {
            $out = self::fetchModels($provider->value, $this->agent, $cache);
            return CliUi::arrayToCli($out);
        } else {
            return CliUi::arrayToCli(['url' => $provider->value->url]);
        }
    }

    /**
     * @cli unpack files from JSON-CONTAINER
     * @cli OUTPUT FORMAT CONTRACT (MANDATORY, DO NOT CHANGE TASK LOGIC):
     * @cli - Output exactly one JSON object, no other text.
     * @cli - Schema name: "JsonContainerV1".
     * @cli - Every JS class/module must be in its own entry under "files" structure: files.filename=data.
     * @cli - Paths are relative POSIX paths, no "..", no duplicates.
     * @cli - pathnames are lowercase only
     * @cli - Use ES module relative imports.
     * @cli
     * @cli END OUTPUT FORMAT CONTRACT (MANDATORY, DO NOT CHANGE TASK LOGIC);
     */
    protected function unpack(ParamFile $json, string $subdir = '', string $dir = '', bool $write = false): string
    {
        $data = Core::jsonRead($json->value);
        $parts = explode('.', $json->filename);
        array_pop($parts);
        $subdir = $subdir ?: basename(implode('.', $parts));
        $dir = $dir ?: $this->datadir;
//        Core::echo(__METHOD__,$data);
        $res = Core::iterate($data['files'], function (string $content, string $path) use ($dir, $subdir, $write) {
            $pathname = $dir . $subdir . '/' . $path;

            if ($write) {
                if (file_exists($pathname)) {
                    $pathname = $pathname = $dir . $subdir . '/_' . $path;
                }
                Core::fileWrite($pathname, $content, 0, true);
                return 'file:' . $pathname . ' size:' . strlen($content);
            } else {
                Core::echo('file', $pathname, 'size:', strlen($content));
            }
        });
        if ($res) {
            return Core::toLog(CliUi::arrayToCli([Colors::get('Written:', Colors::FG_light_green) => $res]));
        } else {
            return '';
        }
    }

    /**
     * @cli pack folder into JsonContainerV1
     */
    protected function pack(string $dir, string $outfile = ''): string
    {
        $dir = strtr($dir, '\\', '/');
        $data = Core::iterate(Core::dirList($dir), function (\SplFileInfo $file) use ($dir) {
            if (!$file->isDir()) {
                return [str_replace($dir, '', strtr($file->getPathname(), '\\', '/')), file_get_contents($file->getRealPath())];
            }
        }, true);
        if ($outfile) {
            Core::fileWrite($outfile, Core::jsonWrite(['schema' => 'JsonContainerV1', 'files' => $data]));
            return Core::toLog('Created: ', $outfile);
        } else {
            return Core::toLog($data);
        }
    }


    public static function extractFile(string $json): array
    {
        return Core::iterate(explode("\n", $json), function ($line) {
            if (trim($line)) {
                return Core::jsonRead($line);
            }
        });
    }

    public static function fetchModels(Provider $provider, string $agent, Cache $cache): array
    {
        $key = md5($provider->modelsurl . $agent);
        if (!$cache->has($key)) {
            Core::echo(__METHOD__, 'cache miss', $provider->modelsurl);
            $data = self::fetch($provider, [], post: false, send: true, agent: $agent);
            if ($data) {
                Core::echo(__METHOD__, 'cache write', $provider->modelsurl);
                $cache->set($key, $data);
            }
        }
        return Core::jsonRead($cache->get($key));
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
        $u = parse_url($url);
        $host = $u['host'] ?? null;
        $port = $u['port'] ?? ($u['scheme'] === 'https' ? 443 : 80);
        $fp = @fsockopen($host, $port, $e, $s, 0.2);
        $online = $fp !== false;
        if ($fp) {
            fclose($fp);
        }
        $out = '';
        if ($online) {
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
                $out = Core::fileReadOnce($url, false, $params);
            } else {
                $out = Core::jsonWrite([__METHOD__, $url, $params]);
            }
        }
        return $out;
    }

    public static function getHeaders(string $bearer, array $headers = [], bool $json = true): array
    {
        $out = [
          'Authorization: Bearer ' . $bearer,
          ...Core::iterate($headers, fn($v, $k) => $k . ': ' . $v)
        ];
        if ($json) {
            $out[] = 'Content-Type: application/json';
        }
        return $out;
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

    private static function createFileUpload(string $content, Provider $provider, string $model = '', bool $asfile = false): array
    {
        // Boundary für multipart/form-data
        $boundary = '--------------------------' . microtime(true);

        // Wenn $asfile=true: normales File hochladen (purpose=assistants) und $content ist der Dateinhalt
        if ($asfile) {
            // Multipart Body zusammenbauen
            $multipart = "--$boundary\r\n";
            // purpose setzen (normaler Upload)
            $multipart .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
            $multipart .= "assistants\r\n";
            $multipart .= "--$boundary\r\n";
            // Datei-Part (Inhalt ist direkt $content)
            $multipart .= "Content-Disposition: form-data; name=\"file\"; filename=\"$model\"\r\n";
            $multipart .= "Content-Type: application/pdf\r\n\r\n";
            $multipart .= Pdf::getPdf($content);
            $multipart .= "\r\n--$boundary--\r\n";
        } else {
            // Sonst: Batch-Upload (purpose=batch) mit JSONL-Request
            $updata = [
                // Optional: custom_id pro Request
              'custom_id' => 'req_1',
                // HTTP method für den Batch-Request
              'method' => 'POST',
                // Ziel-URL des Batch-Jobs (nur Path)
              'url' => parse_url($provider->url, PHP_URL_PATH),
                // Body des eigentlichen Requests
              'body' => [
                  // Model für responses.create
                'model' => $model,
                  // Input als Text
                'input' => $content,
              ],
            ];

            // JSONL: genau eine Zeile
            $jsonl = Core::jsonWrite($updata, 0) . "\n";

            // Multipart Body zusammenbauen
            $multipart = "--$boundary\r\n";
            // purpose setzen (Batch)
            $multipart .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
            $multipart .= "batch\r\n";
            $multipart .= "--$boundary\r\n";
            // Datei-Part (Batch JSONL)
            $multipart .= "Content-Disposition: form-data; name=\"file\"; filename=\"batch.jsonl\"\r\n";
            $multipart .= "Content-Type: application/octet-stream\r\n\r\n";
            $multipart .= $jsonl;
            $multipart .= "\r\n--$boundary--\r\n";
        }
        // Upload Headers
        $uploadHeaders = self::getHeaders($provider->bearer, [
          'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
          ...$provider->headers
        ], false);

        // stream_context Parameter
        return [
          'http' => [
              // POST request
            'method' => 'POST',
              // Header lines
            'header' => implode("\r\n", $uploadHeaders) . "\r\n",
              // Body
            'content' => $multipart,
              // Fehlerbody trotzdem lesen
            'ignore_errors' => true,
          ],
        ];
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

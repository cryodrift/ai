<?php

//declare(strict_types=1);

/**
 * @env AI_APIKEY_HUGGINGFACE=""
 * @env AI_APIKEY_OPENAI=""
 * @env AI_APIKEY_XAI=""
 * @env AI_APIKEY_CLD=""
 * @env AI_DATADIR=".cryodrift/data/ai/"
 * @env AI_CACHEDIR=".cryodrift/cache/ai/"
 */

use cryodrift\ai\Cache;
use cryodrift\ai\ParamModel;
use cryodrift\ai\ParamProvider;
use cryodrift\ai\Provider;
use cryodrift\fw\Core;

if (!isset($ctx)) {
    $ctx = Core::newContext(new \cryodrift\fw\Config());
}

$cfg = $ctx->config();
$cfg[\cryodrift\ai\Cli::class] = [
  'agent' => 'cryodrift/ai cli 0.1',
  'datadir' => Core::env('AI_DATADIR'),
];
$modelproviders = [
  new Provider('oairesp', 'https://api.openai.com/v1/responses', Core::env('AI_APIKEY_OPENAI'), modelsurl: 'https://api.openai.com/v1/models', payload: 'oai'),
  new Provider('xairesp', 'https://api.x.ai/v1/responses', Core::env('AI_APIKEY_XAI'), modelsurl: 'https://api.x.ai/v1/models', payload: 'oai'),
  new Provider('locresp', 'http://127.0.0.1:2001/v1/chat/completions', Core::env('AI_APIKEY_CLD'), modelsurl: 'http://127.0.0.1:2001/v1/models', payload: 'hug'),
  new Provider('cldresp', 'https://api.anthropic.com/v1/messages', Core::env('AI_APIKEY_CLD'), ['x-api-key' => Core::env('AI_APIKEY_CLD'), 'anthropic-version' => '2023-06-01'], modelsurl: 'https://api.anthropic.com/v1/models', payload: 'cld'),
];
$cfg[ParamProvider::class] = [
  'providers' => [
    new Provider('hugchat', 'https://router.huggingface.co/v1/chat/completions', Core::env('AI_APIKEY_HUGGINGFACE'), payload: 'hug'),
    new Provider('oaichat', 'https://api.openai.com/v1/chat/completions', Core::env('AI_APIKEY_OPENAI'), payload: 'hug'),
    new Provider('xaichat', 'https://api.x.ai/v1/chat/completions', Core::env('AI_APIKEY_XAI'), payload: 'hug'),
    ...$modelproviders
  ]
];
$cfg[Cache::class] = [
    'cachedir'=>Core::env('AI_CACHEDIR')
];
$cfg[ParamModel::class] = [
  'agent' => 'cryodrift/ai cli 0.1',
  'providers' => [
    ...$modelproviders
  ]
];

\cryodrift\fw\Router::addConfigs($ctx, [
  'ai' => \cryodrift\ai\Cli::class,
], \cryodrift\fw\Router::TYP_CLI);

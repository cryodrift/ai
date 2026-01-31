<?php

namespace cryodrift\ai;

use cryodrift\fw\cli\CliUi;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\interface\Param;

class ParamModel implements Param
{

    public function __construct(Context $ctx, public string $name, public string $value)
    {
        $providers = Core::getValue('providers', $ctx->config(self::class), []);
        $agent = Core::getValue('agent', $ctx->config(self::class));
        $models = Core::iterate($providers, function (Provider $provider) use ($agent) {
            $provider->url = $provider->modelsurl;
//            Core::echo(__METHOD__, 'provider', $provider->modelsurl);
            return Core::catch(function () use ($provider, $agent) {
                return Core::iterate(Core::extractKeys(Cli::fetchModels($provider, $agent), ['id'], true), function ($v) {
//                Core::echo(__METHOD__,$v);
                    return Core::getValue('id', $v);
                });
            }, false);
        }, false, true);
        $models = array_flip($models);
//        Core::echo(__METHOD__, $models, $value);

        if (Core::getValue($value, $models) === '') {
            throw new \InvalidArgumentException(CliUi::arrayToCli(['choose a model' => $models]));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

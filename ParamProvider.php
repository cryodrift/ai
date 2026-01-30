<?php

namespace cryodrift\ai;

use cryodrift\fw\cli\CliUi;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\interface\Param;

class ParamProvider implements Param
{
    public Provider $value;

    public function __construct(Context $ctx, public string $name, string $value)
    {
        $providers = Core::getValue('providers', $ctx->config(self::class), []);
        $found = Core::iterate($providers, function (Provider $provider) use ($value) {
            if ($value === $provider->id) {
                return $provider;
            }
        });
        if (empty($found)) {
            throw new \InvalidArgumentException(CliUi::arrayToCli(['choose a provider' => Core::iterate($providers, fn(Provider $p) => $p->id)]));
        } else {
            $this->value = Core::pop($found);
        }
    }

    public function __toString(): string
    {
        return $this->value->url;
    }
}

<?php

namespace cryodrift\ai;

class Provider
{

    public function __construct(
      public string $id,
      public string $url,
      public string $bearer,
      public array $headers = [],
      public string $modelsurl = '',
      public string $payload = '',
      public string $inputkey = 'input_file_id'
    ) {
    }

    public static function __set_state(array $data): self
    {
        return new self(...$data);
    }
}

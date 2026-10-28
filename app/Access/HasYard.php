<?php

namespace App\Access;

/**
 * A record that belongs to a yard. People who work only some yards
 * (everyone but Owner and Admin) can't open it from another yard.
 */
interface HasYard
{
    /**
     * The yards it belongs to: working any one of them is enough. Empty
     * when it belongs to no yard in particular.
     *
     * @return list<int>
     */
    public function yardIds(): array;
}

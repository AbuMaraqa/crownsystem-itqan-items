<?php

namespace Modules\GeoLocation\Exceptions;

use DomainException;
use Illuminate\Http\RedirectResponse;

/**
 * A location that other records point to cannot be deleted: the foreign keys cascade, so it would
 * take branches, and through them the staff of those branches, with it. A DomainException, so a bulk
 * delete (`BulkDelete`) keeps that row and shows this message.
 */
class LocationInUseException extends DomainException
{
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}

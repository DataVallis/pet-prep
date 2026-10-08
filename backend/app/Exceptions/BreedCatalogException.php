<?php

namespace App\Exceptions;

use DomainException;

/**
 * A breed_configs change refused because a species would no longer have
 * exactly one free breed (M5-R06-01, QA PR #91 M1; BreedCatalogService).
 */
class BreedCatalogException extends DomainException {}

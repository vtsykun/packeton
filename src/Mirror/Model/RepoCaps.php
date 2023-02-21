<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

enum RepoCaps: string
{
    case V1 = 'API_V1';
    case V2 = 'API_V2';
    case META_CHANGE = 'API_META_CHANGE';
    case LAZY = 'API_V1_LAZY';
    case PACKAGES = 'API_V1_PACKAGES';
    case INCLUDES = 'API_V1_INCLUDES';
}

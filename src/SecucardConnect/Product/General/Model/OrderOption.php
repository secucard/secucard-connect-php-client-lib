<?php
/**
 * Merchants Api Model class
 */

namespace SecucardConnect\Product\General\Model;

use SecucardConnect\Product\Common\Model\BaseModel;
use SecucardConnect\Product\General\Model\LegalDetails;

/**
 * Merchants Api Model class
 *
 */
class OrderOption extends BaseModel
{
    /**
     * @var string
     */
    public $type;

    /**
     * @var bool
     */
    public $enabled;
}
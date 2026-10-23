<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

/**
 * A send refused before anything was created: errors by field of the
 * request (recipient, text, …), safe to show to the employee.
 */
class SendException extends \RuntimeException
{
    /** @var array field => message */
    public array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct((string)reset($errors));
    }
}

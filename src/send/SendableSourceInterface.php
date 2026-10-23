<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * A kind of sendable things of a package ("КП", "Счёт"), registered in the
 * communication component:
 *
 * ```php
 * 'components' => ['communication' => ['sendables' => ['proposal' => ProposalSendableSource::class]]],
 * ```
 *
 * The type (`[a-z][a-z0-9_-]*`) is part of URLs and form values
 * (`proposal:12`). Registrations of several packages merge.
 */
interface SendableSourceInterface
{
    /** Name of the kind for menus: "КП", "Счёт". */
    public function title(): string;

    /** The sendable when the employee may send it, otherwise null. */
    public function find(int $id, User $user): ?SendableInterface;

    /**
     * Items for "Прикрепить из…" in the letter form, newest first: those of
     * $record (the card the letter is written from) first when it is given.
     * Only what find() would return for this employee.
     *
     * @return array[] each: id (int), title (string), hint (string, may be empty)
     */
    public function suggest(User $user, string $q, ?ActiveRecord $record = null, int $limit = 20): array;
}

<?php

namespace craftnet\plugins;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craftnet\errors\LicenseNotFoundException;
use craftnet\Module;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\db\Expression;

class PluginLicenseManager extends Component
{
    /**
     * Normalizes a license key by trimming whitespace and removing dashes.
     *
     * @param string $key
     *
     * @return string
     * @throws InvalidArgumentException if $key is invalid
     */
    public function normalizeKey(string $key): string
    {
        $normalized = trim(preg_replace('/[\-]+/', '', $key));
        if (strlen($normalized) !== 24) {
            throw new InvalidArgumentException('Invalid license key: ' . $key);
        }

        return $normalized;
    }

    /**
     * Returns licenses owned by a user.
     *
     * @param int $ownerId
     *
     * @return PluginLicense[]
     */
    public function getLicensesByOwner(int $ownerId): array
    {
        $results = $this->_createLicenseQuery()
            ->where([
                'l.ownerId' => $ownerId,
            ])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new PluginLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns licenses that need to be renewed in the next 45 days.
     *
     * @param int $ownerId
     *
     * @return PluginLicense[]
     */
    public function getRenewLicensesByOwner(int $ownerId): array
    {
        $date = new \DateTime();
        $date->add(new \DateInterval('P45D'));

        $results = $this->_createLicenseQuery()
            ->where([
                'and',
                [
                    'l.ownerId' => $ownerId,
                ],
                [
                    'and',
                    ['<', 'expiresOn', Db::prepareDateForDb($date)]
                ]
            ])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new PluginLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns sales for a plugin developer
     *
     * @param int $ownerId
     *
     * @return array
     */
    public function getSalesByPluginOwner(int $ownerId): array
    {
        $results = (new Query())
            ->select([
                'lineitems.id AS id',
                'plugins.id AS pluginId',
                'plugins.name AS pluginName',
                'lineitems.salePrice AS grossAmount',
                'users.id AS ownerId',
                'users.firstName AS ownerFirstName',
                'users.lastName AS ownerLastName',
                'users.email AS ownerEmail',
                'lineitems.dateCreated AS saleTime',
                'orders.email AS orderEmail',
            ])
            ->from(['craftnet_pluginlicenses_lineitems licenses_items'])
            ->innerJoin('commerce_lineitems lineitems', '[[lineitems.id]] = [[licenses_items.lineItemId]]')
            ->innerJoin('commerce_orders orders', '[[orders.id]] = [[lineitems.orderId]]')
            ->innerJoin('craftnet_pluginlicenses licenses', '[[licenses.id]] = [[licenses_items.licenseId]]')
            ->innerJoin('craftnet_plugins plugins', '[[plugins.id]] = [[licenses.pluginId]]')
            ->leftJoin('users', '[[users.id]] = [[licenses.ownerId]]')
            ->where(['plugins.developerId' => $ownerId])
            ->orderBy(['lineitems.dateCreated' => SORT_DESC])
            ->all();

        return $results;
    }

    /**
     * Returns licenses purchased by an order.
     *
     * @param int $orderId
     * @return PluginLicense[]]
     */
    public function getLicensesByOrder(int $orderId): array
    {
        $results = $this->_createLicenseQuery()
            ->innerJoin('craftnet_pluginlicenses_lineitems l_li', '[[l_li.licenseId]] = [[l.id]]')
            ->innerJoin('commerce_lineitems li', '[[li.id]] = [[l_li.lineItemId]]')
            ->where(['li.orderId' => $orderId])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new PluginLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns licenses associated with a given Craft license ID.
     *
     * @param int $cmsLicenseId
     *
     * @return PluginLicense[]
     */
    public function getLicensesByCmsLicense(int $cmsLicenseId): array
    {
        $results = $this->_createLicenseQuery()
            ->where(['l.cmsLicenseId' => $cmsLicenseId])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new PluginLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns a license by its key.
     *
     * @param string $key
     * @param string|null $handle the plugin handle
     *
     * @return PluginLicense
     * @throws LicenseNotFoundException if $key is missing
     */
    public function getLicenseByKey(string $key, string $handle = null): PluginLicense
    {
        try {
            $key = $this->normalizeKey($key);
        } catch (InvalidArgumentException $e) {
            throw new LicenseNotFoundException($key);
        }

        $query = $this->_createLicenseQuery()
            ->where(['l.key' => $key]);

        if ($handle !== null) {
            $query
                ->innerJoin('craftnet_plugins p', '[[p.id]] = [[l.pluginId]]')
                ->andWhere(['p.handle' => $handle]);
        }

        $result = $query->one();

        if ($result === null) {
            throw new LicenseNotFoundException($key);
        }

        return new PluginLicense($result);
    }

    /**
     * Returns licenses by CMS license ID.
     *
     * @param int $cmsLicenseId
     *
     * @return PluginLicense[]
     */
    public function getLicensesByCmsLicenseId(int $cmsLicenseId): array
    {
        $results = $this->_createLicenseQuery()
            ->where(['cmsLicenseId' => $cmsLicenseId])
            ->orderBy(['l.pluginHandle' => SORT_ASC])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new PluginLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns licenses by their developer.
     *
     * @param int $developerId
     * @param int|null $offset
     * @param int|null $limit
     * @param int|null $total
     *
     * @return PluginLicense[]
     */
    public function getLicensesByDeveloper(int $developerId, int $offset = null, int $limit = null, int &$total = null): array
    {
        $query = $this->_createLicenseQuery()
            ->innerJoin('craftnet_plugins p', '[[p.id]] = [[l.pluginId]]')
            ->where(['p.developerId' => $developerId]);

        $total = $query->count();
        $results = $query
            ->offset($offset)
            ->limit($limit)
            ->orderBy(['l.dateCreated' => SORT_ASC])
            ->all();
        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new PluginLicense($result);
        }

        return $licenses;
    }

    /**
     * Saves a license.
     *
     * @param PluginLicense $license
     * @param bool $runValidation
     *
     * @return bool if the license validated and was saved
     * @throws Exception if the license validated but didn't save
     */
    public function saveLicense(PluginLicense $license, bool $runValidation = true): bool
    {
        if ($runValidation && !$license->validate()) {
            Craft::info('License not saved due to validation error.', __METHOD__);

            return false;
        }

        if (!$license->pluginId) {
            $license->pluginId = (int)Plugin::find()
                ->select('elements.id')
                ->handle($license->pluginHandle)
                ->scalar();

            if ($license->pluginId === false) {
                throw new Exception("Invalid plugin handle: {$license->pluginHandle}");
            }
        }

        if (!$license->editionId) {
            $license->editionId = (int)PluginEdition::find()
                ->select('elements.id')
                ->pluginId($license->pluginId)
                ->handle($license->edition)
                ->scalar();

            if ($license->editionId === false) {
                throw new Exception("Invalid plugin edition: {$license->edition}");
            }
        }

        $data = [
            'pluginId' => $license->pluginId,
            'editionId' => $license->editionId,
            'ownerId' => $license->ownerId,
            'cmsLicenseId' => $license->cmsLicenseId,
            'pluginHandle' => $license->pluginHandle,
            'edition' => $license->edition,
            'expirable' => $license->expirable,
            'expired' => $license->expired,
            'autoRenew' => $license->autoRenew,
            'email' => $license->email,
            'key' => $license->key,
            'notes' => $license->notes,
            'privateNotes' => $license->privateNotes,
            'lastVersion' => $license->lastVersion,
            'lastAllowedVersion' => $license->lastAllowedVersion,
            'lastActivityOn' => Db::prepareDateForDb($license->lastActivityOn),
            'lastRenewedOn' => Db::prepareDateForDb($license->lastRenewedOn),
            'expiresOn' => Db::prepareDateForDb($license->expiresOn),
            'dateCreated' => Db::prepareDateForDb($license->dateCreated),
        ];

        if (!$license->id) {
            $success = (bool)Craft::$app->getDb()->createCommand()
                ->insert('craftnet_pluginlicenses', $data)
                ->execute();

            // set the ID on the model
            $license->id = (int)Craft::$app->getDb()->getLastInsertID('craftnet_pluginlicenses');
        } else {
            $success = (bool)Craft::$app->getDb()->createCommand()
                ->update('craftnet_pluginlicenses', $data, ['id' => $license->id])
                ->execute();
        }

        if (!$success) {
            throw new Exception('License validated but didn’t save.');
        }

        return true;
    }

    /**
     * Adds a new record to a Craft license’s history.
     *
     * @param int $licenseId
     * @param string $note
     * @param string|null $timestamp
     */
    public function addHistory(int $licenseId, string $note, string $timestamp = null)
    {
        Craft::$app->getDb()->createCommand()
            ->insert('craftnet_pluginlicensehistory', [
                'licenseId' => $licenseId,
                'note' => $note,
                'timestamp' => $timestamp ?? Db::prepareDateForDb(new \DateTime()),
            ], false)
            ->execute();
    }

    /**
     * Returns a license's history in chronological order.
     *
     * @param int $licenseId
     *
     * @return array
     */
    public function getHistory(int $licenseId): array
    {
        return (new Query())
            ->select(['note', 'timestamp'])
            ->from('craftnet_pluginlicensehistory')
            ->where(['licenseId' => $licenseId])
            ->orderBy(['timestamp' => SORT_ASC])
            ->all();
    }

    /**
     * Claims a license for a user.
     *
     * @param User $user
     * @param string $key
     *
     * @throws LicenseNotFoundException
     * @throws Exception
     */
    public function claimLicense(User $user, string $key)
    {
        $key = $this->normalizeKey($key);

        $result = $this->_createLicenseQuery()
            ->where([
                'l.key' => $key,
            ])
            ->one();

        if ($result === null) {
            throw new LicenseNotFoundException($key);
        }

        $license = new PluginLicense($result);

        // make sure the license doesn't already have an owner
        if ($license->ownerId) {
            throw new Exception('License has already been claimed.');
        }

        $license->ownerId = $user->id;
        $license->email = $user->email;

        if (!$this->saveLicense($license)) {
            throw new Exception('Could not save plugin license: ' . implode(', ', $license->getErrorSummary(true)));
        }

        $this->addHistory($license->id, "claimed by {$user->email}");
    }

    /**
     * Finds unclaimed licenses that are associated with orders placed by the given user's email,
     * and and assigns them to the user.
     *
     * @param User $user
     * @param string|null $email the email to look for (defaults to the user's email)
     * @return int the total number of affected licenses
     */
    public function claimLicenses(User $user, string $email = null): int
    {
        return Craft::$app->getDb()->createCommand()
            ->update('craftnet_pluginlicenses', [
                'ownerId' => $user->id,
            ], [
                'and',
                ['ownerId' => null],
                new Expression('lower([[email]]) = :email', [':email' => strtolower($email ?? $user->email)]),
            ], [], false)
            ->execute();
    }

    /**
     * Returns licenses by owner as an array.
     *
     * @param User $owner
     *
     * @return array
     */
    public function getLicensesArrayByOwner(User $owner)
    {
        $results = $this->getLicensesByOwner($owner->id);

        return $this->transformLicensesForOwner($results, $owner);
    }

    public function getSalesArrayByPluginOwner(User $owner)
    {
        $results = $this->getSalesByPluginOwner($owner->id);

        foreach ($results as &$row) {
            $row['netAmount'] = number_format($row['grossAmount'] * 0.8, 2);
            $row['plugin'] = [
                'id' => $row['pluginId'],
                'name' => $row['pluginName']
            ];
            $row['customer'] = [
                'id' => $row['ownerId'],
                'name' => implode(' ', array_filter([$row['ownerFirstName'], $row['ownerLastName']])),
                'email' => $row['ownerEmail'] ?? $row['orderEmail'],
            ];

            unset($row['pluginId'], $row['pluginName'], $row['ownerId'], $row['ownerFirstName'], $row['ownerLastName'], $row['ownerEmail']);
        }

        return $results;
    }

    /**
     * Transforms licenses for the given owner.
     *
     * @param array $results
     * @param User $owner
     *
     * @return array
     */
    public function transformLicensesForOwner(array $results, User $owner)
    {
        $licenses = [];

        foreach ($results as $result) {
            $licenses[] = $this->transformLicenseForOwner($result, $owner);
        }

        return $licenses;
    }

    /**
     * Transforms a license for the given owner.
     *
     * @param CmsLicense $result
     * @param User $owner
     *
     * @return array
     */
    public function transformLicenseForOwner(PluginLicense $result, User $owner)
    {
        if ($result->ownerId === $owner->id) {
            $license = $result->getAttributes(['id', 'editionId', 'key', 'cmsLicenseId', 'email', 'notes', 'autoRenew', 'expirable', 'expired', 'expiresOn', 'dateCreated']);
        } else {
            $license = [
                'shortKey' => $result->getShortKey()
            ];
        }


        // History

        $license['history'] = $this->getHistory($result->id);
        $license['edition'] = PluginEdition::findOne($result->editionId);

        // Plugin

        $plugin = null;

        if ($result->pluginId) {
            $pluginResult = Plugin::find()->id($result->pluginId)->status(null)->one();
            $plugin = $pluginResult->getAttributes(['name', 'handle']);
        }

        $license['plugin'] = $plugin;


        // CMS License

        $cmsLicense = null;

        if ($result->cmsLicenseId) {
            $cmsLicenseResult = Module::getInstance()->getCmsLicenseManager()->getLicenseById($result->cmsLicenseId);

            if ($cmsLicenseResult->ownerId === $owner->id) {
                $cmsLicense = $cmsLicenseResult->getAttributes(['key', 'editionHandle']);
            } else {
                $cmsLicense = [
                    'shortKey' => substr($cmsLicenseResult->key, 0, 10)
                ];
            }
        }

        $license['cmsLicense'] = $cmsLicense;

        return $license;
    }

    /**
     * Deletes a license by its key.
     *
     * @param string $key
     * @throws LicenseNotFoundException if $key is missing
     */
    public function deleteLicenseByKey(string $key)
    {
        try {
            $key = $this->normalizeKey($key);
        } catch (InvalidArgumentException $e) {
            throw new LicenseNotFoundException($key, $e->getMessage(), 0, $e);
        }

        $rows = Craft::$app->getDb()->createCommand()
            ->delete('craftnet_pluginlicenses', ['key' => $key])
            ->execute();

        if ($rows === 0) {
            throw new LicenseNotFoundException($key);
        }
    }

    /**
     * @return Query
     */
    private function _createLicenseQuery(): Query
    {
        return (new Query())
            ->select([
                'l.id',
                'l.pluginId',
                'l.editionId',
                'l.ownerId',
                'l.cmsLicenseId',
                'l.pluginHandle',
                'l.edition',
                'l.expirable',
                'l.expired',
                'l.autoRenew',
                'l.email',
                'l.key',
                'l.notes',
                'l.privateNotes',
                'l.lastVersion',
                'l.lastAllowedVersion',
                'l.lastActivityOn',
                'l.lastRenewedOn',
                'l.expiresOn',
                'l.dateCreated',
                'l.dateUpdated',
                'l.uid',
            ])
            ->from(['craftnet_pluginlicenses l']);
    }
}

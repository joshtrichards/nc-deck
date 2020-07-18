<?php
/**
 * @copyright Copyright (c) 2016 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Deck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;

class AclMapper extends DeckMapper implements IPermissionMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'deck_board_acl', Acl::class);
	}

	public function findAll($boardId, $limit = null, $offset = null) {
		$sql = 'SELECT id, board_id, type, participant, permission_edit, permission_share, permission_manage FROM `*PREFIX*deck_board_acl` WHERE `board_id` = ? ';
		return $this->findEntities($sql, [$boardId], $limit, $offset);
	}

	public function isOwner($userId, $aclId): bool {
		$sql = 'SELECT owner FROM `*PREFIX*deck_boards` WHERE `id` IN (SELECT board_id FROM `*PREFIX*deck_board_acl` WHERE id = ?)';
		$stmt = $this->execute($sql, [$aclId]);
		$row = $stmt->fetch();
		return ($row['owner'] === $userId);
	}

	public function findBoardId($id): ?int {
		try {
			$entity = $this->find($id);
			return $entity->getBoardId();
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
		}
		return null;
	}

	public function findByParticipant($type, $participant): array {
		$sql = 'SELECT * from *PREFIX*deck_board_acl WHERE type = ? AND participant = ?';
		return $this->findEntities($sql, [$type, $participant]);
	}

	/**
	 * @param $ownerId
	 * @param $newOwnerId
	 * @return void
	 */
	public function transferOwnership($ownerId, $newOwnerId) {
		$params = [
			'owner' => $ownerId,
			'newOwner' => $newOwnerId,
			'type' => Acl::PERMISSION_TYPE_USER
		];
		//We want preserve permissions from both users
		$sql = "UPDATE `{$this->tableName}` AS `source` 
                    LEFT JOIN `{$this->tableName}` AS `target` 
                    ON `target`.`participant` = :newOwner AND `target`.`type` = :type 
                    SET `source`.`permission_edit` =(`source`.`permission_edit` || `target`.`permission_edit`), 
                        `source`.`permission_share` =(`source`.`permission_share` || `target`.`permission_share`), 
                        `source`.`permission_manage` =(`source`.`permission_manage` || `target`.`permission_manage`) 
                    WHERE `source`.`participant` = :owner AND `source`.`type` = :type";
		$stmt = $this->execute($sql, $params);
		$stmt->closeCursor();
		//We can't transfer acl if target already in acl
		$sql = "DELETE FROM `{$this->tableName}` 
                    WHERE `participant` = :newOwner 
                        AND `type` = :type 
                        AND EXISTS (SELECT `id` FROM (SELECT `id` FROM `{$this->tableName}` 
                                    WHERE `participant` = :owner AND `type` = :type) as tmp)";
		$stmt = $this->execute($sql, $params);
		$stmt->closeCursor();
		//Now we can transfer without errors
		$sqlUpdate = "UPDATE `{$this->tableName}`  
                        SET `participant` = :newOwner WHERE `participant` = :owner AND `type` = :type";
		$stmt = $this->execute($sqlUpdate, $params);
		$stmt->closeCursor();
	}
}

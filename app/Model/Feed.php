<?php
App::uses('AppModel', 'Model');
/**
 * Feed Model
 *
 * @property Item $Item
 */
class Feed extends AppModel {
/**
 * Display field
 *
 * @var string
 */
	public $displayField = 'title';
/**
 * Validation rules
 *
 * @var array
 */
	public $validate = array(
		'title' => array(
			'notempty' => array(
				'rule' => array('notempty')
			),
		),
		'url' => array(
			'notempty' => array(
				'rule' => array('notempty')
			)
		)
	);

	//The Associations below have been created with all possible keys, those that are not needed can be removed

/**
 * hasMany associations
 *
 * @var array
 */
	public $hasMany = array(
		'Item' => array(
			'className' => 'Item',
			'foreignKey' => 'feed_id',
			'dependent' => false,
			'conditions' => '',
			'fields' => '',
			'order' => '',
			'limit' => '',
			'offset' => '',
			'exclusive' => '',
			'finderQuery' => '',
			'counterQuery' => ''
		)
	);

	function updateAll() {
		$feeds = $this->find('all', array(
			'order' => array('Feed.updated'),
			//'limit' => 1
		));
		
		foreach ($feeds as $feed) {
			$contents = file_get_contents($feed['Feed']['url']);
			$contents = str_replace('& ', '&amp;', $contents);
			$xml = Xml::build($contents);
			unset($contents);
			
			foreach ($xml->channel->item as $item) {
				// check to see if the same guid is already in the database		
				$check = $this->Item->find('count', 
					array('conditions' => 
						array('Item.guid' => $item->guid)
					)
				);
				
				if ($check == 0) {
					$this->Item->create();
					$this->Item->set('feed_id', $feed['Feed']['id']);
					$this->Item->set('title', $item->title);
					
					// set the link - if it isn't present, then tack a hash of the pubdate onto the end of the feed url
					if (empty($item->link)) {
						$this->Item->set('link', $feed['Feed']['url'] . '?' . sha1($item->pubDate));
					} else {
						$this->Item->set('link', $item->link);
					}
					
					$this->Item->set('description', $item->description);
					$this->Item->set('summary', strip_tags($item->description));
					
					// set the pubdate to now if the pubdate is in the future (for some weird reason)
					if (strtotime($item->pubDate) < strtotime(date('Y-m-d H:i:s'))) {
						$pubdate = date('Y-m-d H:i:s', strtotime($item->pubDate));
					} else {
						$pubdate = date('Y-m-d H:i:s');
					}

					$this->Item->set('pubdate', $pubdate);
					$this->Item->set('guid', $item->guid);
					
					if (!empty($item->author))
						$this->Item->set('author', $item->author);
					
					if (!empty($item->enclosure->url))
						$this->Item->set('enclosure', $item->enclosure->url);
					
					$this->Item->save();
					
					// ### cluster the item with other items
					// use fulltext search to find most similar items
					$sql = sprintf("SELECT Item.id, cluster_id, Item.title, 
											MATCH(summary) AGAINST ('%s') score 
									FROM items Item, feeds f
									WHERE Item.feed_id = f.id
										AND f.display = 1
										AND Item.id != %d AND feed_id != %d
										AND Item.created >= CURRENT_TIMESTAMP - INTERVAL 24 HOUR
									HAVING score >= %d
									ORDER BY score DESC
									LIMIT 0, 1",
									mysql_real_escape_string(strip_tags($item->description)),
									$this->Item->id,
									$feed['Feed']['id'],
									Configure::read('Site.cluster_level')
					);
						
					$results = $this->Item->query($sql);	

					// check for existing cluster
					// if one exists, add the item to it
					if (isset($results[0])) {
						$result = $results[0];
						if (!empty($result['Item']['cluster_id']))
							$this->Item->saveField('cluster_id', $result['Item']['cluster_id']);
						else
							$this->Item->saveField('cluster_id', $result['Item']['id']);
					
						echo "matched " . $item->title . " with " . $result['Item']['title'] . "<br />";						
					}

					// #################
					
					$matches = array();

					$text = $item->description;
					preg_match_all('/(http(s?)\:\/\/){1}[^"\'>]+/', $text, $matches);

					if (!empty($matches)) {
						foreach($matches as $match) {
							if (!empty($match[0])) {
								if ( strlen($match[0]) > 9 ) {
									$this->Item->Link->create();
									$this->Item->Link->save(array(
										'item_id' => $this->Item->id,
										'url' => $match[0]
									));
								}
							}
						}
					}				
				}
			}
			
			// update feed's updated field so it won't get checked again immediately
			$this->id = $feed['Feed']['id'];
			$this->saveField('updated', time());
		}
	}
}

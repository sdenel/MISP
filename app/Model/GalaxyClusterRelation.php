<?php
App::uses('AppModel', 'Model');

class GalaxyClusterRelation extends AppModel
{
    public $useTable = 'galaxy_cluster_relations';

    public $recursive = -1;

    public $actsAs = array(
            'Containable',
    );

    public $validate = array(
        'referenced_galaxy_cluster_type' => array(
            'stringNotEmpty' => array(
                'rule' => array('stringNotEmpty')
            )
        ),
        'referenced_galaxy_cluster_uuid' => array(
            'uuid' => array(
                'rule' => array('custom', '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/'),
                'message' => 'Please provide a valid UUID'
            ),
            'unique' => array(
                'rule' => 'isUnique',
                'message' => 'The UUID provided is not unique',
                'required' => 'create'
            )
        ),
        'distribution' => array(
            'rule' => array('inList', array('0', '1', '2', '3', '4')),
            'message' => 'Options: Your organisation only, This community only, Connected communities, All communities, Sharing group',
            'required' => true
        )
    );

    public $belongsTo = array(
            'GalaxyCluster' => array(
                'className' => 'GalaxyCluster',
                'foreignKey' => 'galaxy_cluster_id',
            ),
            'ReferencedGalaxyCluster' => array(
                'className' => 'GalaxyCluster',
                'foreignKey' => 'referenced_galaxy_cluster_id',
            ),
            'Org' => array(
                'className' => 'Organisation',
                'foreignKey' => 'org_id',
                'conditions' => array('GalaxyClusterRelation.org_id !=' => 0),
            ),
            'Orgc' => array(
                'className' => 'Organisation',
                'foreignKey' => 'orgc_id',
                'conditions' => array('GalaxyClusterRelation.orgc_id !=' => 0),
            ),
            'SharingGroup' => array(
                    'className' => 'SharingGroup',
                    'foreignKey' => 'sharing_group_id'
            )
    );

    public $hasMany = array(
        'GalaxyClusterRelationTag' => array('dependent' => true),
    );

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        return true;
    }

    public function buildConditions($user)
    {
        $this->Event = ClassRegistry::init('Event');
        $conditions = array();
        if (!$user['Role']['perm_site_admin']) {
            $sgids = $this->Event->cacheSgids($user, true);
            $conditions['AND']['OR'] = array(
                'GalaxyClusterRelation.org_id' => $user['org_id'],
                array(
                    'AND' => array(
                        'GalaxyClusterRelation.distribution >' => 0,
                        'GalaxyClusterRelation.distribution <' => 4
                    ),
                ),
                array(
                    'AND' => array(
                        'GalaxyClusterRelation.sharing_group_id' => $sgids,
                        'GalaxyClusterRelation.distribution' => 4
                    )
                )
            );
        }
        return $conditions;
    }

    public function getExistingRelationships()
    {
        $existingRelationships = $this->find('list', array(
            'recursive' => -1,
            'fields' => array('referenced_galaxy_cluster_type'),
            'group' => array('referenced_galaxy_cluster_type')
        ), false, false);
        return $existingRelationships;
    }

    public function deleteRelations($conditions)
    {
        $this->deleteAll($conditions, false, false);
    }

    public function addRelations($user, $relations)
    {
        $fieldList = array(
            'galaxy_cluster_id',
            'galaxy_cluster_uuid',
            'referenced_galaxy_cluster_id',
            'referenced_galaxy_cluster_uuid',
            'referenced_galaxy_cluster_type'
        );
        foreach ($relations as $k => $relation) {
            if (!isset($relation['referenced_galaxy_cluster_id'])) {
                $referencedCluster = $this->GalaxyCluster->fetchGalaxyClusters($user, array('conditions' => array('GalaxyCluster.uuid' => $relation['referenced_galaxy_cluster_uuid'])));
                if (!empty($referencedCluster)) { // do not save the relation if referenced cluster does not exists
                    $referencedCluster = $referencedCluster[0];
                    $relation['referenced_galaxy_cluster_id'] = $referencedCluster['GalaxyCluster']['id'];
                    $this->create();
                    $saveResult = $this->save($relation, array('fieldList' => $fieldList));
                    if ($saveResult) {
                        $savedId = $this->id;
                        $this->GalaxyClusterRelationTag->attachTags($user, $savedId, $relation['tags']);
                    }
                }
            }
        }
    }

    public function massageRelationTag($cluster)
    {
        if (!empty($cluster['GalaxyClusterRelation'])) {
            foreach ($cluster['GalaxyClusterRelation'] as $k => $relation) {
                if (!empty($relation['GalaxyClusterRelationTag'])) {
                    foreach ($relation['GalaxyClusterRelationTag'] as $relationTag) {
                        $cluster['GalaxyClusterRelation'][$k]['Tag'] = $relationTag['Tag'];
                    }
                    unset($cluster['GalaxyClusterRelation'][$k]['GalaxyClusterRelationTag']);
                }
            }
        }
        return $cluster;
    }
}

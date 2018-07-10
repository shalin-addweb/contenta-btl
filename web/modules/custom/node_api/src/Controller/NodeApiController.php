<?php

/**
 * @file
 * Contains \Drupal\node_api\Controller\NodeApiController.
 */

namespace Drupal\node_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Controller routines for test_api routes.
 */
class NodeApiController extends ControllerBase {

  /**
   * Callback for 'node_api/nodes/{node_type}' API method.
   */
  public function get_node_api( Request $request, $node_type) {

    $nodeIdLimit = 3;
    $pageSet = 0;

    // Set the simple field params
    $simpleFieldsParams = \Drupal::request()->get('simplefields');

    // Set the refernce field params
    $refernceFieldsParams = \Drupal::request()->get('referncefields');

    // Set the refernce field params
    $searchParams = \Drupal::request()->get('search');

    // Explode it to the Array
    $simpleFieldsParams = explode(',', $simpleFieldsParams);
    $refernceFieldsParams = explode(',', $refernceFieldsParams);

    // Set the for each to create the reference array
    foreach ($refernceFieldsParams as $key => $value) {
      $subRefernceField = explode('.', $value);
      array_reverse($subRefernceField);
      $mainField = array_shift($subRefernceField);
      array_push($simpleFieldsParams, $mainField);
      $referncefieldsArry[$mainField] = $subRefernceField;
    }
    // Filte the array to remove the duplication
    $simpleFieldsParams = array_unique($simpleFieldsParams);

    // Set the refernce field params
    $pageSet = \Drupal::request()->get('page');

    $searchFieldsArray = ['string','text_with_summary'];

    // Get all the fields for the selected node type
    $currentNodeFields = \Drupal::entityManager()->getFieldDefinitions('node', $node_type);

    // Set the handler type for the entity refernce
    foreach ($currentNodeFields as $key => $value) {

      // Check if the field is in the params
      if(in_array($key, $simpleFieldsParams)) {
        // Set the default handler
        $handlerType = 'default:';
        $feildDebugValue = $value->toArray();
        // Set the handler
        if(!empty($feildDebugValue['settings'])) {
          $handler = $feildDebugValue['settings'];
          if(isset($handler['handler'])) {
            $handlerType = $handler['handler'];
          }
        }
        $fieldType = $value->getType();
        if(in_array($fieldType, $searchFieldsArray)) {
          $searchFields[] = $key;
        }
        $fieldValue[$value->getType() . ':' . $handlerType . ':' . $key] = $key;
      }
    }

    // To retrive the nodes for the content type
    if(!empty($node_type)) {
      $nids = node_api_get_nids_value($node_type, $nodeIdLimit, $pageSet, $searchParams, $searchFields);
    }
    // Set the count
    $count = count($nids);


    // Get the node values
    foreach ($nids as $value) {

      $nodeIdsArray[] =[
      'nid'->$value->nid
      ];

      // Load the node
      $nodeValue = node_load($value->nid);

      // Get the specific field details
      foreach ($fieldValue as $key => $fieldDefination) {
        // Explode to check the handler
        $keyDebugValue = explode(':', $key);
        list($field, $default, $entityName, $fieldName) = $keyDebugValue;

        // Set the data values
        if(!empty($nodeValue->get($fieldDefination))) {
          $nodeFiledWiseValue['node:' . $value->nid][$fieldDefination] = $nodeValue->get($fieldDefination)->getString();
          // Check if handler set
          if(!empty($entityName)) {
            // Call the function to get the handler wise term
            if($entityName == 'taxonomy_term') {
              $targetIdsArray = [];
              $targetIdsArray = $nodeValue->get($fieldDefination)->getValue();
              $refernceData = get_refernce_term($entityName, $targetIdsArray, $referncefieldsArry[$fieldDefination]);
              $nodeFiledWiseValue['node:' . $value->nid][$fieldDefination] = $refernceData;
            }
            if($entityName == 'paragraph') {
              $paragraphIds = [];
              $paragraphIds = $nodeValue->get($fieldDefination)->getValue();
              $refernceData = get_refernce_para($entityName, $paragraphIds, $referncefieldsArry[$fieldDefination]);
              $nodeFiledWiseValue['node:' . $value->nid][$fieldDefination] = $refernceData;
            }
            // Call the function to get the handler wise file
            if($entityName == 'file') {
              $uriValue = '';
              $handlerFieldDebugValue = $nodeValue->toArray();
              if(!empty($handlerFieldDebugValue[$fieldDefination])) {
                $uriValue = $nodeValue->$fieldDefination->entity->getFileUri();
                if(!empty($uriValue)) {
                  $refernceData = file_create_url($uriValue);
                  $nodeFiledWiseValue['node:' . $value->nid][$fieldDefination] = $refernceData;
                }
              }
            }
          }
        }
      }
    }

    $response['node'] = $nodeFiledWiseValue;
    $response['count'] = $count;

    return new JsonResponse( $response );
  }
}

/*
* Function to get the nids as per the requirement
*/
function node_api_get_nids_value($nodeType = '', $limit = 10, $offset = 0, $searchParams = '', $searchFields = []) {

  if (!empty($offset)) {
    $offSet = $offset;
  }
  else {
    $offSet = 0;
  }

  $query = \Drupal::database()->select('node_field_data', 'n')
            ->fields('n', ['nid']);
  $query->condition('n.type', $nodeType);
  $query->condition('n.status',1);
  $query->orderBy('n.created', 'DESC');
  $query->range($offSet * $limit, $limit);

  if(!empty($searchParams)) {
    foreach ($searchFields as $value) {
      if($value != 'title') {
        $tableName = 'node__' . $value;
        $query->join($tableName, $tableName, $tableName . '.entity_id = n.nid');
      }
    }
  }

  // // Filter By Search text
  if(!empty($searchParams)) {

    $arr_text = explode(' ', $searchParams);

    // Set or condition for searched text
    $search_or = db_or();
    foreach ($arr_text as $key => $search_text) {
      $search_or->condition('n.title', '%' . $query->escapeLike($search_text) . '%', 'LIKE');
      foreach ($searchFields as $fieldSearch) {
        if($fieldSearch != 'title') {
          $searchTableFieldValue = 'node__' . $fieldSearch . '.' . $fieldSearch . '_value';
          $search_or->condition($searchTableFieldValue, "%" . $query->escapeLike($search_text) . "%", 'LIKE');
        }
      }
    }
    $query->condition($search_or);
  }

  $nids = $query->execute()->fetchAll();
  return $nids;   

}


/*
*
* Function to get the refernce data as per requirement
*/
function get_refernce_term($entityName = '', $nodeFiledWiseValue= [], $fieldDefination = []) {
  $refernceValue = [];
  if(!empty($nodeFiledWiseValue)) {
    foreach ($nodeFiledWiseValue as $value) {
      $tid = $value['target_id'];
      $entity =  \Drupal::entityManager()->getStorage($entityName)->load($value['target_id']);
      if(!empty($entity)) {
        foreach ($fieldDefination as $key => $value) {
          if(!empty($entity->get($value))) {
            $refernceValue['term:' . $tid][$value] = $entity->get($value)->getString();
          }
        }
      }
    }
  }
  return $refernceValue;
}
/*
*
* Function to get the refernce data as per requirement
*/
function get_refernce_para($entityName = '', $nodeFiledWiseValue= [], $fieldDefination = []) {

  $refernceValue = [];
  if(!empty($nodeFiledWiseValue)) {
    foreach ($nodeFiledWiseValue as $value) {
      $tid = $value['target_id'];
      $paragraph = Paragraph::load($tid);
      if(!empty($paragraph)) {
        foreach ($fieldDefination as $key => $value) {
          $paragraphDebugValue = $paragraph->toArray();
          if(!empty($paragraphDebugValue[$value])) {
            $refernceValue['paragraph:' . $tid][$value] = $paragraph->get($value)->getString();
          }
        }
      }
    }
  }
  return $refernceValue;
}
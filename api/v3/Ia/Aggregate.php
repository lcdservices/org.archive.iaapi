<?php
use CRM_Iaapi_ExtensionUtil as E;

/**
 * Ia.Aggregate API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_ia_Aggregate_spec(&$spec) {
  $spec['financial_type_id']['api.required'] = 1;
  $spec['contribution_status_id']['api.required'] = 1;
  $spec['receive_date_from']['api.required'] = 1;
  $spec['receive_date_to']['api.required'] = 1;
}

/**
 * Ia.Aggregate API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_ia_Aggregate($params) {
  $financialTypes = CRM_Contribute_PseudoConstant::financialType();
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus();

  $type = array_search($params['financial_type_id'], $financialTypes);
  $status = array_search($params['contribution_status_id'], $contributionStatus);
  $from = date('Y-m-d H:i:s', strtotime($params['receive_date_from']));
  $to = date('Y-m-d H:i:s', strtotime($params['receive_date_to']));

  if (empty($type) || empty($status) || empty($from) || empty($to)) {
    throw new API_Exception('Missing or invalid parameters.', 9001);
  }

  $sql = "
    SELECT SUM(total_amount) aggregate_amount, source
    FROM civicrm_contribution
    WHERE financial_type_id = %1
      AND contribution_status_id = %2
      AND receive_date BETWEEN %3 AND %4
    GROUP BY source
  ";
  $dao = CRM_Core_DAO::executeQuery($sql, [
    1 => [$type, 'Positive'],
    2 => [$status, 'Positive'],
    3 => [$from, 'String'],
    4 => [$to, 'String'],
  ]);

  $aggregates = [];
  while ($dao->fetch()) {
    $aggregates[$dao->source] = $dao->aggregate_amount;
  }

  //generate totals
  $aggregates['total_one_time'] =
    CRM_Utils_Array::value('Stripe one-time', $aggregates, 0) + CRM_Utils_Array::value('PayPal one-time', $aggregates, 0);
  $aggregates['total_subscription'] =
    (CRM_Utils_Array::value('Stripe subscription', $aggregates, 0) + CRM_Utils_Array::value('PayPal subscription', $aggregates, 0)) * 9;
  $aggregates['total_upsell'] =
    CRM_Utils_Array::value('upsell-donation', $aggregates, 0) * 10;
  $aggregates['total_grand'] = $aggregates['total_one_time'] + $aggregates['total_subscription'] + $aggregates['total_upsell'];

  /*Civi::log()->debug('', [
    'sql' => $sql,
    'aggregates' => $aggregates,
  ]);*/

  return civicrm_api3_create_success($aggregates, $params, 'Ia', 'aggregate');
}

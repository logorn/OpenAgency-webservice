<?php
set_include_path(get_include_path() . PATH_SEPARATOR .
                 __DIR__ . '/../OLS_class_lib/simpletest' . PATH_SEPARATOR .
                 __DIR__ . '/../OLS_class_lib');
require_once('simpletest/autorun.php');
require_once('simpletest/web_tester.php');

define('NS_PREFIX', 'oa');

class TestOfAgency extends WebTestCase {
  private $test_cases;
  private $service_uri;
  private $xmlns;

  function __construct() {
    parent::__construct();
    if (isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1]) {
      $this->service_uri = $GLOBALS['argv'][1];
    }
    else {
      $this->service_uri = 'http://guesstimate.dbc.dk/~fvs/OpenLibrary/OpenAgency/trunk/';
    }
    $this->xmlns = 'xmlns:' . NS_PREFIX . '="http://oss.dbc.dk/ns/openagency"';
    $this->set_test_cases();
  }

  function test_instantiation() {
    $this->get($this->service_uri);
    $this->do_asserts(array(), array(), array(200));
  }

  function test_requests() {
    foreach ($this->test_cases as $test) {
      if ($action = $test['action']['post']) {
        $this->post_service($action, $test['pars'], $test['text'], $test['pattern']);
      }
      if ($action = $test['action']['get']) {
        $this->get_service($action, $test['pars'], $test['text'], $test['pattern']);
      }
    }
  }

  private function set_test_cases() {
  // automation
    $this->test_cases[] = 
      array('action' => array('post' => 'automationRequest', 'get' => 'automation'),
            'pars' => array('agencyId' => 'DK-810010', 'autService' => 'autPotential', 'materialType' => '1'),
            'text' => array('830370'),
            'pattern' => array('/autPotential.*materialType.*responder.*automationResponse/'));
    $this->test_cases[] = 
      array('action' => array('post' => 'automationRequest', 'get' => 'automation'),
            'pars' => array('agencyId' => 'DK-820040', 'autService' => 'autProvider', 'materialType' => '1'),
            'text' => array('820040', 'YES'),
            'pattern' => array('/autProvider.*materialType.*willReceive.*automationResponse/'));
    $this->test_cases[] = 
      array('action' => array('post' => 'automationRequest', 'get' => 'automation'),
            'pars' => array('agencyId' => 'DK-810010', 'autService' => 'autRequester', 'materialType' => '1'),
            'text' => array('810010', 'YES'),
            'pattern' => array('/autRequester.*materialType.*willSend.*automationResponse/'));
  // encryption
    $this->test_cases[] = 
      array('action' => array('post' => 'encryptionRequest', 'get' => 'encryption'),
            'pars' => array('email' => 'bestil@gentofte.bibnet.dk'),
            'text' => array('715700', 'YES', 'BEGIN'),
            'pattern' => array('/encryption.*email.*key/'));
  // endUserOrderPolicy
    $this->test_cases[] = 
      array('action' => array('post' => 'endUserOrderPolicyRequest', 'get' => 'endUserOrderPolicy'),
            'pars' => array('agencyId' => 'DK-710117', 'orderMaterialType' => 'monograph', 'ownedByAgency' => '1'),
            'text' => array('0'),
            'pattern' => array('/willReceive.*condition.*endUserOrderPolicyResponse/'));
  // findLibrary
    $this->test_cases[] = 
      array('action' => array('post' => 'findLibraryRequest', 'get' => 'findLibrary'),
            'pars' => array('agencyId' => 'DK-710100'),
            'text' => array('Krystalgade', '1172'),
            'pattern' => array('/pickupAgency.*branchName.*postalCode.*openingHours.*findLibraryResponse/'));
  // getCulrProfile
    $this->test_cases[] = 
      array('action' => array('post' => 'getCulrProfileRequest', 'get' => 'getCulrProfile'),
            'pars' => array('agencyId' => 'DK-010100', 'profileName' => 'DBCtest-provider'),
            'text' => array('Anders'),
            'pattern' => array('/culrProfile.*profileName.*CreateAccountId.*UpdateAccountId.*getCulrProfileResponse/'));
  // getRegistryInfo
    $this->test_cases[] = 
      array('action' => array('post' => 'getRegistryInfoRequest', 'get' => 'getRegistryInfo'),
            'pars' => array('agencyId' => 'DK-710100'),
            'text' => array('Krystalgade'),
            'pattern' => array('/pickupAgency.*branchName.*postalCode.*openingHours.*getRegistryInfoResponse/',
                               '/z3950Ill.*z3950Address.*getRegistryInfoResponse/'));
  // nameList
    $this->test_cases[] = 
      array('action' => array('post' => 'nameListRequest', 'get' => 'nameList'),
            'pars' => array('libraryType' => 'Folkebibliotek'),
            'text' => array('751000'),
            'pattern' => array('/agency.*agencyId.*agencyName.*nameListResponse/'));
  // pickupAgencyList
    $this->test_cases[] = 
      array('action' => array('post' => 'pickupAgencyListRequest', 'get' => 'pickupAgencyList'),
            'pars' => array('agencyId' => 'DK-710100'),
            'text' => array('Krystalgade', '1172'),
            'pattern' => array('/agencyName.*branchName.*postalCode.*temporarilyClosed/'));
  // remoteAccess
    $this->test_cases[] = 
      array('action' => array('post' => 'remoteAccessRequest', 'get' => 'remoteAccess'),
            'pars' => array('agencyId' => 'DK-718300'),
            'text' => array('Faktalink', 'Filmstriben'),
            'pattern' => array('/agencyId.*subscription.*remoteAccessResponse/'));
  // requestOrder
    $this->test_cases[] = 
      array('action' => array('post' => 'requestOrderRequest', 'get' => 'requestOrder'),
            'pars' => array('agencyId' => 'DK-710100'),
            'text' => array('710100'),
            'pattern' => array('/agencyId.*requestOrderResponse/'));
  // service
    $this->test_cases[] = 
      array('action' => array('post' => 'serviceRequest', 'get' => 'service'),
            'pars' => array('agencyId' => 'DK-710117', 'service' => 'information'),
            'text' => array('Valby'),
            'pattern' => array('/agencyId.*junction.*kvik.*sender/'));
    $this->test_cases[] = 
      array('action' => array('post' => 'serviceRequest', 'get' => 'service'),
            'pars' => array('agencyId' => 'DK-810010', 'service' => 'userOrderParameters'),
            'text' => array('userId'),
            'pattern' => array('/userOrderParameters.*userParameter.*parameterRequired.*borrowerCheckParameters.*serviceResponse/'));
  }

  private function post_service($action, $pars, $texts, $patterns) {
    $post = $this->tag_me($action, $this->tag_pars($pars), $this->xmlns);
    $this->post($this->service_uri, '<?xml version="1.0" encoding="UTF-8"?' . '>' . $post);
    $this->do_asserts($texts, $patterns, array(200));
  }

  private function get_service($action, $pars, $texts, $patterns) {
    $this->get($this->service_uri, array_merge(array('action' => $action), $pars));
    $this->do_asserts($texts, $patterns, array(200));
  }

  private function do_asserts($texts, $patterns, $http_codes = '') {
    if ($http_codes && is_array($http_codes)) {
      $this->assertResponse($http_codes);
    }
    if ($texts && is_array($texts)) {
      foreach ($texts as $i => $text) {
        $this->assertText($text);
      }
    }
    if ($patterns && is_array($patterns)) {
      foreach ($patterns as $pattern) {
        $this->assertPattern($pattern);
      }
    }
  }

  private function tag_pars($pars) {
    $ret = '';
    foreach ($pars as $tag => $val) {
      $ret .= $this->tag_me($tag, $val);
    }
    return $ret;
  }

  private function tag_me($tag, $val, $attr = '') {
    return '<' . NS_PREFIX . ':' . $tag . ($attr ? ' ' . $attr : '') . '>' . $val . '</' . NS_PREFIX . ':' . $tag . '>';
  }

}
?>

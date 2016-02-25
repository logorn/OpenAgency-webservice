<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/** \brief Service to query info the VIP database
 *
 */


require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/oci_class.php');
require_once 'OLS_class_lib/memcache_class.php';

class openAgency extends webServiceServer {
  protected $cache;
  protected $cache_expire = array();

  public function __construct() {
    webServiceServer::__construct('openagency.ini');
    $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                             $this->config->get_value('cache_port', 'setup'),
                             $this->config->get_value('cache_expire', 'setup'));
    $this->cache_expire = $this->config->get_value('cache_operation_expire', 'setup');
  }


  /** \brief Fetch information about automation of ILL
   *
   * Request:
   * - agencyId
   * - AutService: autPotential, autRequester or autProvider
   * - materialType
   * Response:
   * - autPotential
   * or
   * - autProvider
   * or
   * - autRequester
   * or
   * - error
   **/
  public function automation($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_aut_' . $this->config->get_inifile_hash() . $agency . $param->autService->_value . $param->materialType->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        switch ($param->autService->_value) {
          case 'autPotential':
            $this->watch->start('sql1');
            try {
              $oci->bind('bind_laantager', $agency);
              $oci->bind('bind_materiale_id', $param->materialType->_value);
              $oci->set_query('SELECT id_nr, valg
                              FROM vip_fjernlaan
                              WHERE laantager = :bind_laantager
                              AND materiale_id = :bind_materiale_id');
              $vf_row = $oci->fetch_into_assoc();
            }
            catch (ociException $e) {
              verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
              Object::set_value($res, 'error', 'service_unavailable');
            }
            $this->watch->stop('sql1');
            if (empty($res->error)) {
              if ($vf_row['VALG'] == 'a') {
                try {
                  $oci->bind('bind_materiale_id', $param->materialType->_value);
                  $oci->bind('bind_status', 'J');
                  $this->watch->start('sql2');
                  $oci->set_query('SELECT laangiver
                                  FROM vip_fjernlaan
                                  WHERE materiale_id = :bind_materiale_id
                                  AND status = :bind_status');    // ??? NULL og DISTINCT
                  $this->watch->stop('sql2');
                  $ap = &$res->autPotential->_value;
                  Object::set_value($ap, 'materialType', $param->materialType->_value);
                  while ($vf_row = $oci->fetch_into_assoc()) {
                    if ($vf_row['LAANGIVER']) {
                      Object::set_array_value($ap, 'responder', $vf_row['LAANGIVER']);
                    }
                  }
                }
                catch (ociException $e) {
                  $this->watch->stop('sql2');
                  verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                  Object::set_value($res, 'error', 'service_unavailable');
                }
              }
              elseif ($vf_row['VALG'] == 'l') {
                $this->watch->start('sql3');
                try {
                  $oci->bind('bind_fjernlaan_id', $vf_row['ID_NR']);
                  $oci->set_query('SELECT bib_nr
                                  FROM vip_fjernlaan_bibliotek
                                  WHERE fjernlaan_id = :bind_fjernlaan_id');
                  $ap = &$res->autPotential->_value;
                  Object::set_value($ap, 'materialType', $param->materialType->_value);
                  while ($vfb_row = $oci->fetch_into_assoc())
                    Object::set_array_value($ap, 'responder', self::normalize_agency($vfb_row['BIB_NR']));
                }
                catch (ociException $e) {
                  verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                  Object::set_value($res, 'error', 'service_unavailable');
                }
                $this->watch->stop('sql3');
              }
              else {
                Object::set_value($res, 'error', 'no_agencies_found');
              }
            }
            break;
          case 'autRequester':
            try {
              $oci->bind('bind_laantager', $agency);
              $oci->bind('bind_materiale_id', $param->materialType->_value);
              $this->watch->start('sql4');
              $oci->set_query('SELECT *
                              FROM vip_fjernlaan
                              WHERE laantager = :bind_laantager
                              AND materiale_id = :bind_materiale_id');
              $this->watch->stop('sql4');
              $ar = &$res->autRequester->_value;
              Object::set_value($ar, 'requester', $agency);
              Object::set_value($ar, 'materialType', $param->materialType->_value);
              if ($vf_row = $oci->fetch_into_assoc()) {
                Object::set_value($ar, 'willSend', self::parse_will_send($vf_row['STATUS']));
                Object::set_value($ar, 'willSendOwn', self::parse_will_send($vf_row['STATUS_EGET']));
                $profile_fom = $this->config->get_value('profile_for_own_material','setup');
                if (!$profile = $profile_fom[strtolower($vf_row['PROFIL_EGET'])]) {
                  $profile = reset($profile_fom);
                }
                Object::set_value($ar, 'ownMaterialAgeInDays', $profile['age_in_days']);
                Object::set_value($ar, 'ownDeliveryLimitInDays', $profile['limit_in_days']);
                Object::set_value($ar, 'autPeriod', $vf_row['PERIODE']);
                Object::set_value($ar, 'autId', $vf_row['ID_NR']);
                Object::set_value($ar, 'autChoice', $vf_row['VALG']);
                Object::set_value($ar, 'autRes', ($vf_row['RESERVERING'] == 'J' ? 'YES' : 'NO'));
              }
              else {
                Object::set_value($ar, 'willSend', 'NO');
                Object::set_value($ar, 'willSendOwn', 'NO');
              }
            }
            catch (ociException $e) {
              $this->watch->stop('sql4');
              verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
              Object::set_value($res, 'error', 'service_unavailable');
            }
            break;
          case 'autProvider':
            try {
              $oci->bind('bind_laangiver', $agency);
              $oci->bind('bind_materiale_id', $param->materialType->_value);
              $this->watch->start('sql5');
              $oci->set_query('SELECT *
                              FROM vip_fjernlaan
                              WHERE laangiver = :bind_laangiver
                              AND materiale_id = :bind_materiale_id');
              $this->watch->stop('sql5');
              $ap = &$res->autProvider->_value;
              Object::set_value($ap, 'provider', $agency);
              Object::set_value($ap, 'materialType', $param->materialType->_value);
              if ($vf_row = $oci->fetch_into_assoc()) {
                Object::set_value($ap, 'willReceive',  ($vf_row['STATUS'] == 'J' ? 'YES' : 'NO'));
                Object::set_value($ap, 'autPeriod',  $vf_row['PERIODE']);
                Object::set_value($ap, 'autId',  $vf_row['ID_NR']);
              }
              else
                Object::set_value($ap, 'willSend', 'NO');
            }
            catch (ociException $e) {
              $this->watch->stop('sql5');
              verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
              Object::set_value($res, 'error', 'service_unavailable');
            }
            break;
          default:
            Object::set_value($res, 'error', 'error_in_request');
        }
      }
    }
    //print_r($res); var_dump($param); die();
    @ $ret->automationResponse->_value = $res;
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch encryption to use when sending mails
   *
   * Request:
   * - email
   * Response:
   * - encryption
   * - - encrypt
   * - - email
   * - - agencyId
   * - - key
   * - - base64
   * - - date
   * or
   * - error
   */
  public function encryption($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $cache_key = 'OA_enc_' . $this->config->get_inifile_hash() . $param->email->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
          $oci->bind('bind_email', $param->email->_value);
          $this->watch->start('sql1');
          $oci->set_query('SELECT * FROM vip_krypt WHERE email = :bind_email');
          $this->watch->stop('sql1');
          while ($vk_row = $oci->fetch_into_assoc()) {
            Object::set_value($o, 'encrypt', 'YES');
            Object::set_value($o, 'email', $param->email->_value);
            Object::set_value($o, 'agencyId', $vk_row['BIBLIOTEK']);
            Object::set_value($o, 'key', $vk_row['KEY']);
            Object::set_value($o, 'base64', ($vk_row['NOTBASE64'] == 'ja' ? 'NO' : 'YES'));
            Object::set_value($o, 'date', $vk_row['UDL_DATO']);
            Object::set_array_value($res, 'encryption', $o);
            unset($o);
          }
          if (empty($res)) {
            Object::set_value($o, 'encrypt', 'NO');
            Object::set_array_value($res, 'encryption', $o);
          }
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
    }

    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'encryptionResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch endUserOrderPolicy - which item can be ordered by externals systems
   *
   * Request:
   * - agencyId
   * - orderMaterialType
   * - ownedByAgency
   * Response:
   * - willReceive
   * - condition
   * or
   * - error
   */
  public function endUserOrderPolicy($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $mat_type = strtolower($param->orderMaterialType->_value);
      $cache_key = 'OA_endUOP_' . $this->config->get_inifile_hash() . $agency . $param->orderMaterialType->_value . $param->ownedByAgency->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        $assoc['cdrom']     = array('CDROM_BEST_MODT', 'BEST_TEKST_CDROM');
        $assoc['journal']   = array('PER_BEST_MODT',   'BEST_TEKST_PER');
        $assoc['monograph'] = array('MONO_BEST_MODT',  'BEST_TEKST');
        $assoc['music']     = array('MUSIK_BEST_MODT', 'BEST_TEKST_MUSIK');
        $assoc['newspaper'] = array('AVIS_BEST_MODT',  'BEST_TEKST_AVIS');
        $assoc['video']     = array('VIDEO_BEST_MODT', 'BEST_TEKST_VIDEO');
        if (self::xs_boolean($param->ownedByAgency->_value)) {
          $fjernl = '';
        }
        else {
          $fjernl = '_FJL';
        }
        if (isset($fjernl) && $assoc[$mat_type]) {
          $will_receive = $assoc[$mat_type][0] . $fjernl;
          $this->watch->start('sql1');
          try {
            $oci->bind('bind_bib_nr', $agency);
            $this->watch->start('sql1');
            $oci->set_query('SELECT best_modt, ' . $will_receive . ' "WR", vt.*, vte.*
                            FROM vip_beh vb, vip_txt vt, vip_txt_eng vte
                            WHERE vb.bib_nr = :bind_bib_nr
                            AND vb.bib_nr = vt.bib_nr (+)
                            AND vb.bib_nr = vte.bib_nr (+)');
            $this->watch->stop('sql1');
            if ($vb_row = $oci->fetch_into_assoc()) {
              Object::set_value($res, 'willReceive',
                ($vb_row['BEST_MODT'] == 'J' && ($vb_row['WR'] == 'J' || $vb_row['WR'] == 'B') ? 1 : 0));
              if ($vb_row['WR'] == 'B') {
                $col = $assoc[$mat_type][1] . $fjernl;
                $res->condition[] = self::value_and_language($vb_row[$col], 'dan');
                $res->condition[] = self::value_and_language($vb_row[$col.'_E'], 'eng');
              }
            }
          }
          catch (ociException $e) {
            $this->watch->stop('sql1');
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            Object::set_value($res, 'error', 'service_unavailable');
          }
          $this->watch->stop('sql1');
        }
        else
          Object::set_value($res, 'error', 'error_in_request');
      }
      if (empty($res)) {
        Object::set_value($res, 'error', 'no_agencies_found');
      }
    }

    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'endUserOrderPolicyResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch the CulrProfile
   *
   * Request:
   * - agencyId
   * - profileName
   * - requesterIp
   * Response:
   * - culrProfile (see xsd for parameters)
   * or
   * - error
   */
  public function getCulrProfile($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 551))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $profile_name = $param->profileName->_value;
      $trusted_ip = self::trusted_culr_ip($param->authentication->_value, $param->requesterIp->_value);
      $cache_key = 'OA_getCP' . $this->config->get_inifile_hash() . $agency . $profile_name;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
          $oci->bind('bind_agency', $agency);
          $oci->bind('bind_profile_name', $profile_name);
          $this->watch->start('sql1');
          $oci->set_query('SELECT * FROM vip_culr_profile 
                           WHERE bib_nr = :bind_agency 
                             AND profilename = :bind_profile_name');
          $this->watch->stop('sql1');
          while ($cp_row = $oci->fetch_into_assoc()) {
            Object::set_value($cp, 'agencyId', self::normalize_agency($cp_row['BIB_NR']));
            Object::set_value($cp, 'profileName', $cp_row['PROFILENAME']);
            Object::set_value($cp, 'typeOfClient', $cp_row['TYPEOFCLIENT']);
            Object::set_value($cp, 'contactTechName', $cp_row['CONTACT_TECH_NAME']);
            Object::set_value($cp, 'contactTechMail', $cp_row['CONTACT_TECH_EMAIL']);
            Object::set_value($cp, 'contactTechPhone', $cp_row['CONTACT_TECH_PHONE']);
            Object::set_value($cp, 'contactAdmName', $cp_row['CONTACT_ADM_NAME']);
            Object::set_value($cp, 'contactAdmMail', $cp_row['CONTACT_ADM_EMAIL']);
            Object::set_value($cp, 'contactAdmPhone', $cp_row['CONTACT_ADM_PHONE']);
            Object::set_value($cp, 'CreateAccountId', self::J_is_true($cp_row['CREATEACCOUNTID']));
            Object::set_value($cp, 'CreatePatronId', self::J_is_true($cp_row['CREATEPATRONID']));
            Object::set_value($cp, 'CreateProviderId', $trusted_ip ? self::J_is_true($cp_row['CREATEPROVIDERID']) : '0');
            Object::set_value($cp, 'DeleteAccountId', self::J_is_true($cp_row['DELETEACCOUNTID']));
            Object::set_value($cp, 'DeletePatronId', self::J_is_true($cp_row['DELETEPATRONID']));
            Object::set_value($cp, 'DeleteProviderId', $trusted_ip ? self::J_is_true($cp_row['DELETEPROVIDERID']) : '0');
            Object::set_value($cp, 'GetAccountIdsByAccountId', self::J_is_true($cp_row['GETACCOUNTIDSBYACCOUNTID']));
            Object::set_value($cp, 'GetAccountIdsByPatronId', self::J_is_true($cp_row['GETACCOUNTIDSBYPATRONID']));
            Object::set_value($cp, 'GetAccountIdsByProviderId', self::J_is_true($cp_row['GETACCOUNTIDSBYPROVIDERID']));
            Object::set_value($cp, 'GetMunicipalityNoByAccountId', self::J_is_true($cp_row['GETMUNICIPALITYNOBYACCOUNTID']));
            Object::set_value($cp, 'GetMunicipalityNoByPatronId', self::J_is_true($cp_row['GETMUNICIPALITYNOBYPATRONID']));
            Object::set_value($cp, 'GetPatronIdsByAccountId', self::J_is_true($cp_row['GETPATRONIDSBYACCOUNTID']));
            Object::set_value($cp, 'GetPatronIdsByProviderId', self::J_is_true($cp_row['GETPATRONIDSBYPROVIDERID']));
            Object::set_value($cp, 'GetProviderIdsByAccountId', self::J_is_true($cp_row['GETPROVIDERIDSBYACCOUNTID']));
            Object::set_value($cp, 'GetProviderIdsByPatronId', self::J_is_true($cp_row['GETPROVIDERIDSBYPATRONID']));
            Object::set_value($cp, 'MergePatronIds', self::J_is_true($cp_row['MERGEPATRONIDS']));
            Object::set_value($cp, 'UnrelatePatronIdAndAccountId', self::J_is_true($cp_row['UNRELATEPATRONIDANDACCOUNTID']));
            Object::set_value($cp, 'UpdateAccountId', self::J_is_true($cp_row['UPDATEACCOUNTID']));
            Object::set_array_value($res, 'culrProfile', $cp);
            unset($cp);
          }
          if (empty($res)) {
            Object::set_value($res, 'error', 'profile_not_found');
          }
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'getCulrProfileResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief getRegistryInfo
   *
   * Request:
   * - agencyId
   * - agencyName
   * - lastUpdated
   * - libraryType
   * - libraryStatus
   * Response:
   * - registryInfo (see xsd for parameters)
   * or
   * - error
   */
  public function getRegistryInfo($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_getRI' . 
                   $this->config->get_inifile_hash() . 
                   $agency . 
                   $param->agencyName->_value . 
                   $param->lastUpdated->_value . 
                   $param->libraryType->_value . 
                   $param->libraryStatus->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
      // remove all libraries starting with 3, 5 or 6 - cannot be part of getRegistryInfo
          //$sqls[] = '(v.bib_nr < 300000 OR (v.bib_nr >= 400000 AND v.bib_nr < 500000) OR v.bib_nr >= 700000)';
      // remove all libraries starting with 5 or 6 - cannot be part of getRegistryInfo
            $sqls[] = '(v.bib_nr < 500000 OR v.bib_nr >= 700000)';
        // agencyId
            if ($agency) {
              $sqls[] = 'v.bib_nr = :bind_bib_nr';
              $oci->bind('bind_bib_nr', $agency);
            }
        // agencyName
            if ($val = $param->agencyName->_value) {
              $sqls[] = '(regexp_like(upper(v.navn), upper(:bind_navn))' .
                        ' OR (regexp_like(upper(sup.tekst), upper(:bind_navn)) AND sup.type = :bind_n))';
              $oci->bind('bind_navn', self::build_regexp_like($val));
              $oci->bind('bind_n', 'N');
            }
        // lastUpdated
            if ($val = $param->lastUpdated->_value) {
              $sqls[] = '(v.dato >= TO_DATE(:bind_date, \'YYYY-MM-DD\')' .
                        ' OR v.bs_dato >= TO_DATE(:bind_date, \'YYYY-MM-DD\')' .
                        ' OR vsn.dato >= TO_DATE(:bind_date, \'YYYY-MM-DD\'))' .
              $oci->bind('bind_date', $val);
            }
        // libraryType
            if ($val = $param->libraryType->_value
              && ($param->libraryType->_value == 'Folkebibliotek'
                || $param->libraryType->_value == 'Forskningsbibliotek'
                || $param->libraryType->_value == 'Skolebibliotek')) {
              $sqls[] = 'vsn.bib_type = :bind_bib_type';
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
            else {    // Alle or NULL
              $sqls[] = 'vsn.bib_type != :bind_bib_type';
              $oci->bind('bind_bib_type', 'Skolebibliotek');
              //$sqls[] = '(vsn.bib_type = :bind_bib_type_1 OR vsn.bib_type = :bind_bib_type_2)';
              //$oci->bind('bind_bib_type_1', 'Folkebibliotek');
              //$oci->bind('bind_bib_type_2', 'Forskningsbibliotek');
            }
        // libraryStatus
            if ($param->libraryStatus->_value == 'usynlig') {
              $oci->bind('bind_u', 'U');
              $sqls[] = 'v.delete_mark = :bind_u';
            } elseif ($param->libraryStatus->_value == 'slettet') {
              $oci->bind('bind_s', 'S');
              $sqls[] = 'v.delete_mark = :bind_s';
            } elseif ($param->libraryStatus->_value <> 'alle') {
              $oci->bind('bind_u', 'U');
              $sqls[] = '(v.delete_mark is null OR v.delete_mark = :bind_u)';
            }
            $filter_sql = implode(' AND ', $sqls);
            $sql ='SELECT v.bib_nr, v.navn, v.navn_e, v.navn_k, v.navn_e_k, v.type, v.tlf_nr, v.email, v.badr, 
                          v.bpostnr, v.bcity, v.isil, v.kmd_nr, v.url_homepage, v.url_payment, v.delete_mark,
                          v.afsaetningsbibliotek, v.afsaetningsnavn_k, v.knudepunkt, v.p_nr, v.uni_c_nr, 
                          TO_CHAR(v.dato, \'YYYY-MM-DD\') dato, TO_CHAR(v.bs_dato, \'YYYY-MM-DD\') bs_dato,
                          vsn.navn vsn_navn, vsn.bib_nr vsn_bib_nr, vsn.bib_type vsn_bib_type,
                          vsn.email vsn_email, vsn.tlf_nr vsn_tlf_nr, vsn.fax_nr vsn_fax_nr, 
                          TO_CHAR(vsn.dato, \'YYYY-MM-DD\') vsn_dato, vsn.oclc_symbol, 
                          vsn.cvr_nr vsn_cvr_nr, vsn.p_nr vsn_p_nr, vsn.ean_nummer vsn_ean_nummer,
                          vb.best_modt, vb.best_modt_luk, vb.best_modt_luk_eng,
                          txt.aabn_tid, txt.kvt_tekst_fjl, eng.aabn_tid_e, eng.kvt_tekst_fjl_e, hold.holdeplads,
                          bestil.url_serv_dkl, bestil.support_email, bestil.support_tlf, bestil.ncip_address, bestil.ncip_password,
                          kat.url_best_blanket, kat.url_best_blanket_text, kat.url_laanerstatus, kat.ncip_lookup_user,
                          kat.ncip_renew, kat.ncip_cancel, kat.ncip_update_request, kat.filial_vsn,
                          vd.mailbestil_via, vd.url_itemorder_bestil, vd.zbestil_groupid, vd.zbestil_userid, vd.zbestil_passw,
                          vd.holdingsformat, vd.svar_email,
                          ors.shipping ors_shipping, ors.cancel ors_cancel, ors.answer ors_answer, 
                          ors.cancelreply ors_cancelreply, ors.cancel_answer_synchronic ors_cancel_answer_synchronic,
                          ors.renew ors_renew, ors.renewanswer ors_renewanswer, 
                          ors.renew_answer_synchronic ors_renew_answer_synchronic,
                          ors.iso18626_address, ors.iso18626_password
                  FROM vip v, vip_vsn vsn, vip_danbib vd, vip_beh vb, vip_txt txt, vip_txt_eng eng, 
                       vip_sup sup, vip_bogbus_holdeplads hold, vip_bestil bestil, vip_kat kat, open_agency_ors ors
                  WHERE 
                    ' . $filter_sql . '
                    AND v.kmd_nr = vsn.bib_nr (+)
                    AND v.bib_nr = vd.bib_nr (+)
                    AND v.bib_nr = vb.bib_nr (+)
                    AND v.bib_nr = sup.bib_nr (+)
                    AND v.bib_nr = txt.bib_nr (+)
                    AND v.bib_nr = hold.bib_nr (+)
                    AND v.bib_nr = eng.bib_nr (+)
                    AND v.bib_nr = bestil.bib_nr (+)
                    AND v.bib_nr = ors.bib_nr (+)
                    AND v.bib_nr = kat.bib_nr (+)
                  ORDER BY vsn.bib_nr ASC, v.bib_nr ASC';
            $this->watch->start('sql1');
            $oci->set_query($sql);
            $this->watch->stop('sql1');
            while ($row = $oci->fetch_into_assoc()) {
              if (empty($curr_bib)) {
                $curr_bib = $row['BIB_NR'];
              }
              if ($curr_bib <> $row['BIB_NR']) {
                Object::set_array_value($res, 'registryInfo', $registryInfo);
                unset($registryInfo);
                $curr_bib = $row['BIB_NR'];
              }
              if ($row) {
                self::fill_pickupAgency($registryInfo->pickupAgency->_value, $row);
                $dbc_target = $this->config->get_value('dbc_target', 'setup');
                if ($row['HOLDINGSFORMAT'] == 'B') {
                  self::use_dbc_as_z3950_target($row, $dbc_target['z3950'], $param->authentication->_value);
                  self::use_dbc_as_iso18626_target($row, $dbc_target['iso18626']);
                }
                if ($row['MAILBESTIL_VIA'] == 'C') {
                  self::set_z3950Ill($registryInfo, $row);
                }
                elseif ($row['MAILBESTIL_VIA'] == 'E') {
                  self::set_iso18626($registryInfo, $row);
                  if (empty($row['URL_ITEMORDER_BESTIL']) || !in_array($row['HOLDINGSFORMAT'], array('A', '', NULL))) {
                    self::use_dbc_as_z3950_target($row, $dbc_target['z3950'], $param->authentication->_value);
                  }
                  self::set_z3950Ill($registryInfo, $row, FALSE);
                }
              }
            }
            if ($registryInfo) {
              Object::set_array_value($res, 'registryInfo', $registryInfo);
            }
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'getRegistryInfoResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief Fetch SaouLicenseInfo
   *
   * Request:
   * - agencyId
   * Response:
   * - saouLicenseInfo (see xsd for parameters)
   * or
   * - error
   */
  public function getSaouLicenseInfo($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_getSLI' . $this->config->get_inifile_hash() . $agency;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
          if ($agency) {
            $oci->bind('bind_agency', $agency);
            $where = 'ud.bib_nr = :bind_agency AND ';
          }
          $fb_licens = 'fb_licens';
          $oci->bind('bind_fb_licens', $fb_licens);
          $this->watch->start('sql1');
          $oci->set_query('SELECT ud.bib_nr, domain, proxyurl 
              FROM user_domains ud, licensguide lg
              WHERE ' . $where . '
              origin_source = :bind_fb_licens
              AND ud.bib_nr = lg.bib_nr (+)
              ORDER BY bib_nr');
          $this->watch->stop('sql1');
          $last_bib = '';
          while ($sl_row = $oci->fetch_into_assoc()) {
            if ($last_lib != $sl_row['BIB_NR']) {
              if ($last_lib) {
                Object::set_array_value($res, 'saouLicenseInfo', $sl);
                unset($sl);
              }
              $last_lib = $sl_row['BIB_NR'];
              Object::set_value($sl, 'agencyId', $sl_row['BIB_NR']);
              if ($sl_row['PROXYURL']) {
                Object::set_value($sl, 'proxyUrl', $sl_row['PROXYURL']);
              }
            }
            Object::set_array_value($sl, 'ipAddress', $sl_row['DOMAIN']);
          }
          if ($sl) {
            Object::set_array_value($res, 'saouLicenseInfo', $sl);
          }
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'getSaouLicenseInfoResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief Fetch search profiles for the openSearch service
   *
   * Request:
   * - agencyId
   * Response:
   * - searchCollection
   * - - agencyId
   * - - profile
   * - - - profileName
   * - - - source
   * - - - - sourceName
   * - - - - sourceIdentifier
   * - - - - 1 above or 2 below
   * - - - - sourceOwner
   * - - - - sourceFormat
   */
  public function searchCollection($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_opeSC_' . $this->config->get_inifile_hash() . $agency;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
          // hent alle broend_to_kilder med searchable == "Y" og loop over dem.
          // find de kilder som profilen kender:
          // Søgbarheden (sourceSearchable) gives hvis den findes i den givne profil (broendkilde_id findes)
          // Søgbargeden kan evt. begrænses af broend_to_kilder.access_for
          $oci->bind('bind_y', 'Y');
          $this->watch->start('sql1');
          $oci->set_query('SELECT DISTINCT *
              FROM broend_to_kilder
              WHERE searchable = :bind_y
              ORDER BY upper(name)');
          $kilder_res = $oci->fetch_all_into_assoc();
          $this->watch->stop('sql1');
          foreach ($kilder_res as $kilde) {
            $kilder[$kilde['ID_NR']] = $kilde;
          }
          if ($agency) {
            $oci->bind('bind_agency', $agency);
            $sql_add = ' AND broend_to_profiler.bib_nr = :bind_agency';
          }
          $this->watch->start('sql2');
          $oci->set_query('SELECT broendkilde_id, profil_id, name, broend_to_profiler.bib_nr
              FROM broendprofil_to_kilder, broend_to_profiler
              WHERE broendprofil_to_kilder.broendkilde_id IS NOT NULL
              AND broendprofil_to_kilder.profil_id IS NOT NULL
              AND broend_to_profiler.id_nr = broendprofil_to_kilder.profil_id (+)' . $sql_add);
          $profil_res = $oci->fetch_all_into_assoc();
          $this->watch->stop('sql2');
          $profiles = array();
          foreach ($profil_res as $pr) {
            if ($pr['PROFIL_ID'] && $pr['BROENDKILDE_ID']) {
              $profiles[$pr['BIB_NR']][$pr['PROFIL_ID']][$pr['NAME']][] = $pr['BROENDKILDE_ID'];
            }
          }
          foreach ($profiles as $agency => $agency_profiles) {
            foreach ($agency_profiles as $profile) {
              foreach ($profile as $profile_name => $kilde_ids) {
                foreach ($kilde_ids as $kilde_id) {
                  if ($kilder[$kilde_id] && (empty($kilder[$kilde_id]['ACCESS_FOR']) || strpos($kilder[$kilde_id]['ACCESS_FOR'], $agency) !== FALSE)) {
                    Object::set_value($s->_value, 'sourceName', $kilder[$kilde_id]['NAME']);
                    Object::set_value($s->_value, 'sourceIdentifier', str_replace('[agency]', $agency, $kilder[$kilde_id]['IDENTIFIER']));
                    $source[] = $s;
                    unset($s);
                  }
                }
                if ($source) {
                  Object::set_value($p->_value, 'profileName', $profile_name);
                  $p->_value->source = $source;
                  $res_profile[] = $p;
                  unset($p);
                  unset($source);
                } 
              } 
            }
            if ($res_profile) {
              Object::set_value($a, 'agencyId', $agency);
              $a->profile = $res_profile;
              Object::set_array_value($res, 'searchCollection', $a);
              unset($a);
              unset($res_profile);
            }
          }
          if (empty($res)) {
            Object::set_value($res, 'error', 'profile_not_found');
          }
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          $this->watch->stop('sql2');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'searchCollectionResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetching different info for the ORS-system
   *
   * Request:
   * - agencyId
   * - service
   * Response (depending on service):
   * -  information - see xsd for parameters
   * or orsAnswer - see xsd for parameters
   * or orsCancel - see xsd for parameters
   * or orsCancelReply - see xsd for parameters
   * or orsCancelRequestUser - see xsd for parameters
   * or orsEndUserRequest - see xsd for parameters
   * or orsEndUserIllRequest - see xsd for parameters
   * or orsItemRequest - see xsd for parameters
   * or orsLookupUser - see xsd for parameters
   * or orsRecall - see xsd for parameters
   * or orsReceipt - see xsd for parameters
   * or orsRenew - see xsd for parameters
   * or orsRenewAnswer - see xsd for parameters
   * or orsRenewItemUser - see xsd for parameters
   * or orsShipping - see xsd for parameters
   * or orsStatusRequest - see xsd for parameters
   * or orsStatusResponse - see xsd for parameters
   * or serverInformation - see xsd for parameters
   * or userOrderParameters - see xsd for parameters
   * or userParameters - see xsd for parameters
   * or error
   */
  public function service($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_ser_' . $this->config->get_inifile_hash() . $agency . $param->service->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        $tab_col['v'] = array('bib_nr', 'navn', 'tlf_nr', 'fax_nr', 'email', 'badr', 'bpostnr', 'bcity', 'type', '*');
        $tab_col['vv'] = array('bib_nr', 'navn', 'tlf_nr', 'fax_nr', 'email', 'badr', 'bpostnr', 'bcity', 'bib_type', '*');
        $tab_col['vb'] = array('bib_nr', '*');
        $tab_col['vbst'] = array('bib_nr', 'ncip_address', '*');
        $tab_col['vd'] = array('bib_nr', 'svar_fax', 'svar_email', '*');
        $tab_col['vk'] = array('bib_nr', '*');
        $tab_col['oao'] = array('bib_nr', '*');
        foreach ($tab_col as $prefix => $arr) {
          foreach ($arr as $col) {
            $q .= (empty($q) ? '' : ', ') .
              $prefix . '.' . $col .
              ($col == '*' ? '' : ' "' . strtoupper($prefix . '.' . $col) . '"');
          }
        }
        try {
          $oci->bind('bind_bib_nr', $agency);
          $this->watch->start('sql1');
          $oci->set_query('SELECT ' . $q . '
              FROM vip v, vip_vsn vv, vip_beh vb, vip_bestil vbst, vip_danbib vd, vip_kat vk, open_agency_ors oao
              WHERE v.bib_nr = vd.bib_nr (+)
              AND v.kmd_nr = vv.bib_nr (+)
              AND v.bib_nr = vk.bib_nr (+)
              AND v.bib_nr = vb.bib_nr (+)
              AND v.bib_nr = vbst.bib_nr (+)
              AND v.bib_nr = oao.bib_nr (+)
              AND v.bib_nr = :bind_bib_nr');
          $oa_row = $oci->fetch_into_assoc();
          $this->watch->stop('sql1');
          self::sanitize_array($oa_row);
          if ($param->service->_value == 'information') {
            $consortia = array();
            if ($oa_row['FILIAL_VSN'] <> 'J' && $oa_row['KMD_NR']) {
              $help = $oa_row['KMD_NR'];
            }
            else {
              $help = $agency;
            }
            $oci->bind('bind_bib_nr', $help);
            $this->watch->start('sql2');
            $oci->set_query('SELECT *
                FROM vip_viderestil
                WHERE bib_nr = :bind_bib_nr');
            while ($row = $oci->fetch_into_assoc()) {
              $vv_row[$row['BIB_NR_VIDERESTIL']] = $row;
            }
            $this->watch->stop('sql2');
            if ($vv_row) {
              $oci->bind('bind_bib_nr', $help);
              $this->watch->start('sql3');
              $oci->set_query('SELECT vilse 
                  FROM vip, laaneveje
                  WHERE (vip.kmd_nr = bibliotek OR vip.bib_nr = bibliotek)
                  AND vip.bib_nr = :bind_bib_nr
                  ORDER BY prionr DESC');
              while ($lv_row = $oci->fetch_into_assoc()) {
                if ($p = $vv_row[$lv_row['VILSE']]) {
                  $consortia[] = $p;
                }
              }
              $this->watch->stop('sql3');
              if (count($vv_row) <> count($consortia)) {
                verbose::log(ERROR, 'OpenAgency('.__LINE__.'):: agency ' . $agency . 
                    ' has libraries in VIP_VIDERESTIL not found in LAANEVEJE');
              }
            }
          }
          if ($param->service->_value == 'userOrderParameters') {
            if ($oa_row['FILIAL_VSN'] <> 'J' && $oa_row['KMD_NR']) {
              $oci->bind('bind_bib_nr', $oa_row['KMD_NR']);
            }
            else {
              $oci->bind('bind_bib_nr', $agency);
            }
            $this->watch->start('sql4');
            $oci->set_query('SELECT fjernadgang.har_laanertjek fjernadgang_har_laanertjek, fjernadgang.*, 
                fjernadgang_andre.*
                FROM fjernadgang, fjernadgang_andre
                WHERE fjernadgang.faust (+) = fjernadgang_andre.faust
                AND bib_nr = :bind_bib_nr');
            $fjernadgang_rows = $oci->fetch_all_into_assoc();
            $this->watch->stop('sql4');
          }
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
          $this->watch->stop('sql1');
          $this->watch->stop('sql2');
          $this->watch->stop('sql3');
          $this->watch->stop('sql4');
        }
        if (empty($oa_row)) {
          Object::set_value($res, 'error', 'agency_not_found');
        }
        if (empty($res->error)) {
          //        verbose::log(TRACE, 'OpenAgency('.__LINE__.'):: action=service&agencyId=' . $param->agencyId->_value .  '&service=' . $param->service->_value);
          switch ($param->service->_value) {
            case 'information':
              $inf = &$res->information->_value;
              Object::set_value($inf, 'agencyId', self::normalize_agency($oa_row['VV.BIB_NR']));
              Object::set_value($inf, 'agencyName', $oa_row['VV.NAVN']);
              Object::set_value($inf, 'agencyPhone', $oa_row['VV.TLF_NR']);
              Object::set_value($inf, 'agencyFax', $oa_row['VV.FAX_NR']);
              Object::set_value($inf, 'agencyEmail', $oa_row['VV.EMAIL']);
              Object::set_value($inf, 'agencyType', self::set_agency_type($oa_row['VV.BIB_NR'], $oa_row['VV.BIB_TYPE']));
              Object::set_value($inf, 'agencyCatalogueUrl', $oa_row['URL_BIB_KAT']);
              Object::set_value($inf, 'branchId', self::normalize_agency($oa_row['V.BIB_NR']));
              Object::set_value($inf, 'branchName', $oa_row['V.NAVN']);
              Object::set_value($inf, 'branchPhone', $oa_row['V.TLF_NR']);
              Object::set_value($inf, 'branchFax', $oa_row['VD.SVAR_FAX']);
              Object::set_value($inf, 'branchEmail', $oa_row['V.EMAIL']);
              Object::set_value($inf, 'branchType', $oa_row['V.TYPE']);
              if ($oa_row['AFSAETNINGSBIBLIOTEK'])
                Object::set_value($inf, 'dropOffAgency', $oa_row['AFSAETNINGSBIBLIOTEK']);
              Object::set_value($inf, 'postalAddress', $oa_row['V.BADR']);
              Object::set_value($inf, 'postalCode', $oa_row['V.BPOSTNR']);
              Object::set_value($inf, 'city', $oa_row['V.BCITY']);
              Object::set_value($inf, 'isil', $oa_row['ISIL']);
              Object::set_value($inf, 'junction', $oa_row['KNUDEPUNKT']);
              Object::set_value($inf, 'kvik', ($oa_row['KVIK'] == 'kvik' ? 'YES' : 'NO'));
              Object::set_value($inf, 'lookupUrl', $oa_row['URL_VIDERESTIL']);
              Object::set_value($inf, 'norfri', ($oa_row['NORFRI'] == 'norfri' ? 'YES' : 'NO'));
              Object::set_value($inf, 'requestOrder', $oa_row['USE_LAANEVEJ']);
              Object::set_value($inf, 'sender', self::normalize_agency($oa_row['CHANGE_REQUESTER']));
              if (is_null($inf->sender->_value))
                Object::set_value($inf, 'sender', self::normalize_agency($oa_row['V.BIB_NR']));
              Object::set_value($inf, 'replyToEmail', $oa_row['VD.SVAR_EMAIL']);
              foreach ($consortia as $c_key => &$c) {
                Object::set_value($inf->consortia[$c_key]->_value, 'agencyId', $c['BIB_NR_VIDERESTIL']);
                Object::set_value($inf->consortia[$c_key]->_value, 'lookupUrl', $c['URL_VIDERESTIL']);
              }
              //print_r($oa_row); var_dump($res->information->_value); die();
              break;
            case 'orsAnswer':
              $orsA = &$res->orsAnswer->_value;
              Object::set_value($orsA, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E' || $oa_row['ANSWER'] == '18626') {
                self::fill_iso18626_protocol($orsA, $oa_row);
              }
              else {
                Object::set_value($orsA, 'willReceive', (in_array($oa_row['ANSWER'], array('z3950', 'mail', 'ors')) ? 'YES' : ''));
                Object::set_value($orsA, 'synchronous', 0);
                Object::set_value($orsA, 'protocol', self::normalize_iso18626($oa_row['ANSWER']));
                if ($oa_row['ANSWER'] == 'z3950') {
                  Object::set_value($orsA, 'address', $oa_row['ANSWER_Z3950_ADDRESS']);
                }
                elseif ($oa_row['ANSWER'] == 'mail') {
                  Object::set_value($orsA, 'address', $oa_row['ANSWER_MAIL_ADDRESS']);
                }
                Object::set_value($orsA, 'userId', $oa_row['ANSWER_Z3950_USER']);
                Object::set_value($orsA, 'groupId', $oa_row['ANSWER_Z3950_GROUP']);
                Object::set_value($orsA, 'passWord', ($oa_row['ANSWER'] == 'z3950' ? $oa_row['ANSWER_Z3950_PASSWORD'] : $oa_row['ANSWER_NCIP_AUTH']));
              }
              //var_dump($res->orsAnswer->_value); die();
              break;
            case 'orsCancelRequestUser':
              $orsCRU = &$res->orsCancelRequestUser->_value;
              Object::set_value($orsCRU, 'responder', self::normalize_agency($oa_row['VK.BIB_NR']));
              Object::set_value($orsCRU, 'willReceive', ($oa_row['NCIP_CANCEL'] == 'J' ? 'YES' : 'NO'));
              Object::set_value($orsCRU, 'synchronous', 0);
              Object::set_value($orsCRU, 'address', $oa_row['NCIP_CANCEL_ADDRESS']);
              Object::set_value($orsCRU, 'passWord', $oa_row['NCIP_CANCEL_PASSWORD']);
              //var_dump($res->orsCancelRequestUser->_value); die();
              break;
            case 'orsEndUserRequest':
              $orsEUR = &$res->orsEndUserRequest->_value;
              Object::set_value($orsEUR, 'responder', self::normalize_agency($oa_row['VB.BIB_NR']));
              Object::set_value($orsEUR, 'willReceive', ($oa_row['BEST_MODT'] == 'J' ? 'YES' : 'NO'));
              Object::set_value($orsEUR, 'synchronous', 0);
              switch ($oa_row['BESTIL_VIA']) {
                case 'A':
                  Object::set_value($orsEUR, 'protocol', 'mail');
                  Object::set_value($orsEUR, 'address', $oa_row['EMAIL_BESTIL']);
                  Object::set_value($orsEUR, 'format', 'text');
                  break;
                case 'B':
                  Object::set_value($orsEUR, 'protocol', 'mail');
                  Object::set_value($orsEUR, 'address', $oa_row['EMAIL_BESTIL']);
                  Object::set_value($orsEUR, 'format', 'ill0');
                  break;
                case 'C':
                  Object::set_value($orsEUR, 'protocol', 'ors');
                  break;
                case 'D':
                  Object::set_value($orsEUR, 'protocol', 'ncip');
                  Object::set_value($orsEUR, 'address', $oa_row['VBST.NCIP_ADDRESS']);
                  Object::set_value($orsEUR, 'passWord', $oa_row['NCIP_PASSWORD']);
                  break;
              }
              //var_dump($res->orsEndUserRequest->_value); die();
              break;
            case 'orsEndUserIllRequest':
              $orsEUIR = &$res->orsEndUserIllRequest->_value;
              Object::set_value($orsEUIR, 'responder', self::normalize_agency($oa_row['VB.BIB_NR']));
              Object::set_value($orsEUIR, 'willReceive', ($oa_row['BEST_MODT'] == 'J' ? 'YES' : 'NO'));
              Object::set_value($orsEUIR, 'synchronous', 0);
              switch ($oa_row['BESTIL_FJL_VIA']) {
                case 'A':
                  Object::set_value($orsEUIR, 'protocol', 'mail');
                  Object::set_value($orsEUIR, 'address', $oa_row['EMAIL_FJL_BESTIL']);
                  Object::set_value($orsEUIR, 'format', 'text');
                  break;
                case 'B':
                  Object::set_value($orsEUIR, 'protocol', 'mail');
                  Object::set_value($orsEUIR, 'address', $oa_row['EMAIL_FJL_BESTIL']);
                  Object::set_value($orsEUIR, 'format', 'ill0');
                  break;
                case 'C':
                  Object::set_value($orsEUIR, 'protocol', 'ors');
                  break;
              }
              break;
            case 'orsItemRequest':
              $orsIR = &$res->orsItemRequest->_value;
              Object::set_value($orsIR, 'responder', self::normalize_agency($oa_row['VD.BIB_NR']));
              switch ($oa_row['MAILBESTIL_VIA']) {
                case 'A':
                  Object::set_value($orsIR, 'willReceive', 'YES');
                  Object::set_value($orsIR, 'synchronous', 0);
                  Object::set_value($orsIR, 'protocol', 'mail');
                  Object::set_value($orsIR, 'address', $oa_row['BEST_EMAIL']);
                  break;
                case 'B':
                  Object::set_value($orsIR, 'willReceive', 'YES');
                  Object::set_value($orsIR, 'synchronous', 0);
                  Object::set_value($orsIR, 'protocol', 'ors');
                  break;
                case 'C':
                  Object::set_value($orsIR, 'willReceive', 'YES');
                  Object::set_value($orsIR, 'synchronous', 0);
                  Object::set_value($orsIR, 'protocol', 'z3950');
                  Object::set_value($orsIR, 'address', $oa_row['URL_ITEMORDER_BESTIL']);
                  break;
                case 'E':
                  self::fill_iso18626_protocol($orsIR, $oa_row);
                  break;
                case 'D':
                default:
                  Object::set_value($orsIR, 'willReceive', 'NO');
                  if ($oa_row['BEST_TXT']) {
                    $orsIR->reason = self::value_and_language($oa_row['BEST_TXT'], 'dan');
                  }
                  break;
              }
              if (in_array($orsIR->protocol->_value, array('mail', 'ors', 'z3950'))) {
                if ($oa_row['ZBESTIL_USERID'])
                  Object::set_value($orsIR, 'userId', $oa_row['ZBESTIL_USERID']);
                if ($oa_row['ZBESTIL_GROUPID'])
                  Object::set_value($orsIR, 'groupId', $oa_row['ZBESTIL_GROUPID']);
                if ($oa_row['ZBESTIL_PASSW'])
                  Object::set_value($orsIR, 'passWord', $oa_row['ZBESTIL_PASSW']);
                if ($orsIR->protocol->_value == 'mail') {
                  switch ($oa_row['FORMAT_BEST']) {
                    case 'illdanbest':
                      Object::set_value($orsIR, 'format', 'text');
                      break;
                    case 'ill0form':
                    case 'ill5form':
                      Object::set_value($orsIR, 'format', 'ill0');
                      break;
                  }
                }
              }
              //var_dump($res->orsItemRequest->_value); die();
              break;
            case 'orsLookupUser':
              $orsLU = &$res->orsLookupUser->_value;
              Object::set_value($orsLU, 'responder', self::normalize_agency($oa_row['VK.BIB_NR']));
              Object::set_value($orsLU, 'willReceive', ($oa_row['NCIP_LOOKUP_USER'] == 'J' ? 'YES' : 'NO'));
              Object::set_value($orsLU, 'synchronous', 0);
              Object::set_value($orsLU, 'address', $oa_row['NCIP_LOOKUP_USER_ADDRESS']);
              Object::set_value($orsLU, 'passWord', $oa_row['NCIP_LOOKUP_USER_PASSWORD']);
              //var_dump($res->orsLookupUser->_value); die();
              break;
            case 'orsRecall':
              $orsR = &$res->orsRecall->_value;
              Object::set_value($orsR, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E') {
                self::fill_iso18626_protocol($orsR, $oa_row);
              }
              else {
                Object::set_value($orsR, 'willReceive', (in_array($oa_row['RECALL'], array('z3950', 'mail', 'ors')) ? 'YES' : ''));
                Object::set_value($orsR, 'synchronous', 0);
                Object::set_value($orsR, 'protocol', self::normalize_iso18626($oa_row['RECALL']));
                Object::set_value($orsR, 'address', '');
                Object::set_value($orsR, 'userId', $oa_row['RECALL_Z3950_USER']);
                Object::set_value($orsR, 'groupId', $oa_row['RECALL_Z3950_GROUP']);
                Object::set_value($orsR, 'passWord', ($oa_row['RECALL'] == 'z3950' ? $oa_row['RECALL_Z3950_PASSWORD'] : $oa_row['RECALL_NCIP_AUTH']));
                if ($oa_row['RECALL'] == 'z3950')
                  Object::set_value($orsR, 'address', $oa_row['RECALL_Z3950_ADDRESS']);
              }
              //var_dump($res->orsRecall->_value); die();
              break;
            case 'orsReceipt':
              $orsR = &$res->orsReceipt->_value;
              Object::set_value($orsR, 'responder', self::normalize_agency($oa_row['VD.BIB_NR']));
              Object::set_value($orsR, 'willReceive', (in_array($oa_row['MAILKVITTER_VIA'], array('A', 'B', 'C')) ? 'YES' : 'NO'));
              Object::set_value($orsR, 'synchronous', 0);
              if ($oa_row['MAILKVITTER_VIA'] == 'C') {
                Object::set_value($orsR, 'protocol', 'https');
                Object::set_value($orsR, 'address', $oa_row['OPENRECEIPT_URL']);
                Object::set_value($orsR, 'passWord', $oa_row['OPENRECEIPT_PASSWORD']);
                Object::set_value($orsR, 'format', 'xml');
              }
              else {
                if ($oa_row['MAILKVITTER_VIA'] == 'A') {
                  Object::set_value($orsR, 'protocol', 'mail');
                }
                elseif ($oa_row['MAILKVITTER_VIA'] == 'B') {
                  Object::set_value($orsR, 'protocol', 'ors');
                }
                else {
                  Object::set_value($orsR, 'protocol', '');
                }
                Object::set_value($orsR, 'address', $oa_row['KVIT_EMAIL']);
                if ($oa_row['FORMAT_KVIT'] == 'ill0form') {
                  Object::set_value($orsR, 'format', 'ill0');
                }
                elseif ($oa_row['FORMAT_KVIT'] == 'ill5form') {
                  Object::set_value($orsR, 'format', 'ill0');
                }
                elseif ($oa_row['FORMAT_KVIT'] == 'illdanbest') {
                  Object::set_value($orsR, 'format', 'text');
                }
              }
              //var_dump($res->orsReceipt->_value); die();
              break;
            case 'orsRenew':
              $orsR = &$res->orsRenew->_value;
              Object::set_value($orsR, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E') {
                self::fill_iso18626_protocol($orsR, $oa_row);
              }
              else {
                if ($oa_row['RENEW'] == 'z3950' || $oa_row['RENEW'] == 'ors') {
                  Object::set_value($orsR, 'willReceive', 'YES');
                  Object::set_value($orsR, 'synchronous', '0');
                  Object::set_value($orsR, 'protocol', self::normalize_iso18626($oa_row['RENEW']));
                  if ($oa_row['RENEW'] == 'z3950') {
                    Object::set_value($orsR, 'address', $oa_row['RENEW_Z3950_ADDRESS']);
                    Object::set_value($orsR, 'userId', $oa_row['RENEW_Z3950_USER']);
                    Object::set_value($orsR, 'groupId', $oa_row['RENEW_Z3950_GROUP']);
                    Object::set_value($orsR, 'passWord', $oa_row['RENEW_Z3950_PASSWORD']);
                  }
                }
                else {
                  Object::set_value($orsR, 'willReceive', 'NO');
                  Object::set_value($orsR, 'synchronous', '0');
                }
              }
              //var_dump($res->orsRenew->_value); die();
              break;
            case 'orsRenewAnswer':
              $orsRA = &$res->orsRenewAnswer->_value;
              Object::set_value($orsRA, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E' || $oa_row['ANSWER'] == '18626') {
                self::fill_iso18626_protocol($orsRA, $oa_row);
              }
              else {
                if ($oa_row['RENEW'] == 'z3950' || $oa_row['RENEW'] == 'ors') {
                  if ($oa_row['RENEWANSWER'] == 'z3950' || $oa_row['RENEWANSWER'] == 'ors') {
                    Object::set_value($orsRA, 'willReceive', 'YES');
                    Object::set_value($orsRA, 'synchronous', $oa_row['RENEW_ANSWER_SYNCHRONIC'] == 'J' ? 1 : 0);
                    Object::set_value($orsRA, 'protocol', self::normalize_iso18626($oa_row['RENEWANSWER']));
                    if ($oa_row['RENEWANSWER'] == 'z3950') {
                      Object::set_value($orsRA, 'address', $oa_row['RENEWANSWER_Z3950_ADDRESS']);
                      Object::set_value($orsRA, 'userId', $oa_row['RENEWANSWER_Z3950_USER']);
                      Object::set_value($orsRA, 'groupId', $oa_row['RENEWANSWER_Z3950_GROUP']);
                      Object::set_value($orsRA, 'passWord', $oa_row['RENEWANSWER_Z3950_PASSWORD']);
                    }
                  }
                  else {
                    Object::set_value($orsRA, 'willReceive', 'NO');
                    Object::set_value($orsRA, 'synchronous', 0);
                  }
                }
              }
              //var_dump($res->orsRenewAnswer->_value); die();
              break;
            case 'orsCancel':
              $orsC = &$res->orsCancel->_value;
              Object::set_value($orsC, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E') {
                self::fill_iso18626_protocol($orsC, $oa_row);
              }
              else {
                if ($oa_row['CANCEL'] == 'z3950' || $oa_row['CANCEL'] == 'ors') {
                  Object::set_value($orsC, 'willReceive', 'YES');
                  Object::set_value($orsC, 'synchronous', 0);
                  Object::set_value($orsC, 'protocol', self::normalize_iso18626($oa_row['CANCEL']));
                  if ($oa_row['CANCEL'] == 'z3950') {
                    Object::set_value($orsC, 'address', $oa_row['CANCEL_Z3950_ADDRESS']);
                    Object::set_value($orsC, 'userId', $oa_row['CANCEL_Z3950_USER']);
                    Object::set_value($orsC, 'groupId', $oa_row['CANCEL_Z3950_GROUP']);
                    Object::set_value($orsC, 'passWord', $oa_row['CANCEL_Z3950_PASSWORD']);
                  }
                }
                else {
                  Object::set_value($orsC, 'willReceive', 'NO');
                  Object::set_value($orsC, 'synchronous', 0);
                }
              }
              //var_dump($res->orsCancel->_value); die();
              break;
            case 'orsCancelReply':
              $orsCR = &$res->orsCancelReply->_value;
              Object::set_value($orsCR, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E' || $oa_row['ANSWER'] == '18626') {
                self::fill_iso18626_protocol($orsCR, $oa_row);
              }
              else {
                if ($oa_row['CANCELREPLY'] == 'z3950' || $oa_row['CANCELREPLY'] == 'ors') {
                  Object::set_value($orsCR, 'willReceive', 'YES');
                  Object::set_value($orsCR, 'synchronous', $oa_row['CANCEL_ANSWER_SYNCHRONIC'] == 'J' ? 1 : 0);
                  Object::set_value($orsCR, 'protocol', self::normalize_iso18626($oa_row['CANCELREPLY']));
                  if ($oa_row['CANCELREPLY'] == 'z3950') {
                    Object::set_value($orsCR, 'address', $oa_row['CANCELREPLY_Z3950_ADDRESS']);
                    Object::set_value($orsCR, 'userId', $oa_row['CANCELREPLY_Z3950_USER']);
                    Object::set_value($orsCR, 'groupId', $oa_row['CANCELREPLY_Z3950_GROUP']);
                    Object::set_value($orsCR, 'passWord', $oa_row['CANCELREPLY_Z3950_PASSWORD']);
                  }
                }
                else {
                  Object::set_value($orsCR, 'willReceive', 'NO');
                  Object::set_value($orsCR, 'synchronous', 0);
                }
              }
              //var_dump($res->orsCancelReply->_value); die();
              break;
            case 'orsRenewItemUser':
              $orsRIU = &$res->orsRenewItemUser->_value;
              Object::set_value($orsRIU, 'responder', self::normalize_agency($oa_row['VK.BIB_NR']));
              Object::set_value($orsRIU, 'willReceive', ($oa_row['NCIP_RENEW'] == 'J' ? 'YES' : 'NO'));
              Object::set_value($orsRIU, 'synchronous', 0);
              Object::set_value($orsRIU, 'address', $oa_row['NCIP_RENEW_ADDRESS']);
              Object::set_value($orsRIU, 'passWord', $oa_row['NCIP_RENEW_PASSWORD']);
              //var_dump($res->orsRenewItemUser->_value); die();
              break;
            case 'orsShipping':
              $orsS = &$res->orsShipping->_value;
              Object::set_value($orsS, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              if ($oa_row['MAILBESTIL_VIA'] == 'E' || $oa_row['ANSWER'] == '18626') {
                self::fill_iso18626_protocol($orsS, $oa_row);
              }
              else {
                Object::set_value($orsS, 'willReceive', (in_array($oa_row['SHIPPING'], array('z3950', 'mail', 'ors')) ? 'YES' : ''));
                Object::set_value($orsS, 'synchronous', 0);
                Object::set_value($orsS, 'protocol', self::normalize_iso18626($oa_row['SHIPPING']));
                Object::set_value($orsS, 'address', '');
                Object::set_value($orsS, 'userId', $oa_row['SHIPPING_Z3950_USER']);
                Object::set_value($orsS, 'groupId', $oa_row['SHIPPING_Z3950_GROUP']);
                Object::set_value($orsS, 'passWord', ($oa_row['SHIPPING'] == 'z3950' ? $oa_row['SHIPPING_Z3950_PASSWORD'] : $oa_row['SHIPPING_NCIP_AUTH']));
                if ($oa_row['SHIPPING'] == 'z3950')
                  Object::set_value($orsS, 'address', $oa_row['SHIPPING_Z3950_ADDRESS']);
              }
              //var_dump($res->orsShipping->_value); die();
              break;
            case 'orsStatusRequest':
              $orsSR = &$res->orsStatusRequest->_value;
              Object::set_value($orsSR, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              Object::set_value($orsSR, 'willReceive', '');
              if ($oa_row['MAILBESTIL_VIA'] == 'E') {
                self::fill_iso18626_protocol($orsSR, $oa_row);
              }
              break;
            case 'orsStatusResponse':
              $orsSR = &$res->orsStatusResponse->_value;
              Object::set_value($orsSR, 'responder', self::normalize_agency($oa_row['OAO.BIB_NR']));
              Object::set_value($orsSR, 'willReceive', '');
              if ($oa_row['MAILBESTIL_VIA'] == 'E' || $oa_row['ANSWER'] == '18626') {
                self::fill_iso18626_protocol($orsSR, $oa_row);
              }
              break;
            case 'serverInformation':
              $serI = &$res->serverInformation->_value;
              Object::set_value($serI, 'responder', self::normalize_agency($oa_row['VD.BIB_NR']));
              Object::set_value($serI, 'isil', $oa_row['ISIL']);
              if ($oa_row['ISO20775_URL']) {
                Object::set_value($serI, 'protocol', 'iso20775');
                Object::set_value($serI, 'address', $oa_row['ISO20775_URL']);
                Object::set_value($serI, 'passWord', $oa_row['ISO20775_PASSWORD']);
              }
              elseif ($oa_row['URL_ITEMORDER_BESTIL']) {
                Object::set_value($serI, 'protocol', 'z3950');
                Object::set_value($serI, 'address', $oa_row['URL_ITEMORDER_BESTIL']);
                Object::set_value($serI, 'userId', $oa_row['ZBESTIL_USERID']);
                Object::set_value($serI, 'groupId', $oa_row['ZBESTIL_GROUPID']);
                Object::set_value($serI, 'passWord', $oa_row['ZBESTIL_PASSW']);
              }
              else {
                Object::set_value($serI, 'protocol', 'none');
              }
              //var_dump($res->serverInformation->_value); die();
              break;
            case 'userParameters':
              $usrP = &$res->userParameters->_value;
              $get_obl = array('LD_CPR' => 'cpr',
                  'LD_ID' => 'common',
                  'LD_LKST' => 'barcode',
                  'LD_KLNR' => 'cardno',
                  'LD_TXT' => 'optional');
              Object::set_value($usrP, 'userIdType', 'no_userid_selected');
              foreach ($get_obl as $key => $val) {
                if (substr($oa_row[$key],0,1) == 'O') {
                  Object::set_value($usrP, 'userIdType', $val);
                  break;
                }
              }
              break;
            case 'userOrderParameters':
              $usrOP = &$res->userOrderParameters->_value;
              $u_fld = array('LD_CPR' => 'cpr', 
                  'LD_ID' => 'userId',
                  'LD_TXT' => 'customId',
                  'LD_LKST' => 'barcode',
                  'LD_KLNR' => 'cardno',
                  'LD_PIN' => 'pincode',
                  'LD_DATO' => 'userDateOfBirth',
                  'LD_NAVN' => 'userName',
                  'LD_ADR' => 'userAddress',
                  'LD_EMAIL' => 'userMail',
                  'LD_TLF' => 'userTelephone');
              foreach ($u_fld as $vip_key => $res_key) {
                $sw = $oa_row[$vip_key][0];
                if (in_array($sw, array('J', 'O'))) {
                  Object::set_value($f, 'userParameterType', $res_key);
                  Object::set_value($f, 'parameterRequired', ($sw == 'O'? '1' : '0'));
                  Object::set_array_value($usrOP, 'userParameter', $f);
                  unset($f);
                }
              }
              if (in_array($oa_row['LD_ID'][0], array('J', 'O'))) {
                if ($oa_row['LD_ID_TXT']) {
                  $usrOP->userIdTxt[] = self::value_and_language($oa_row['LD_ID_TXT'], 'dan');
                }
                if ($oa_row['LD_ID_TXT_ENG']) {
                  $usrOP->userIdTxt[] = self::value_and_language($oa_row['LD_ID_TXT_ENG'], 'eng');
                }
              }
              if (in_array($oa_row['LD_TXT'][0], array('J', 'O'))) {
                if ($oa_row['LD_TXT2']) {
                  $usrOP->customIdTxt[] = self::value_and_language($oa_row['LD_TXT2'], 'dan');
                }
                if ($oa_row['LD_TXT2_ENG']) {
                  $usrOP->customIdTxt[] = self::value_and_language($oa_row['LD_TXT2_ENG'], 'eng');
                }
              }
              $per = array('PER_NR' => 'volume',
                  'PER_HEFTE' => 'issue',
                  'PER_AAR' => 'publicationDateOfComponent',
                  'PER_SIDE' => 'pagination',
                  'PER_FORFATTER' => 'authorOfComponent',
                  'PER_TITEL' => 'titleOfComponent',
                  'PER_KILDE' => 'userReferenceSource');
              foreach ($per as $key => $val)
                $per_ill[$key . '_FJL'] = $val;
              $avis = array('AVIS_DATO' => 'issue',
                  'AVIS_AAR' => 'publicationDateOfComponent',
                  'AVIS_FORFATTER' => 'authorOfComponent',
                  'AVIS_TITEL' => 'titleOfComponent',
                  'AVIS_KILDE' => 'userReferenceSource');
              foreach ($avis as $key => $val)
                $avis_ill[$key . '_FJL'] = $val;
              $m_fld = array('CDROM_BEST_MODT' => array('cdrom', 'local'),
                  'CDROM_BEST_MODT_FJL' => array('cdrom', 'ill'),
                  'MONO_BEST_MODT' => array('monograph', 'local'),
                  'MONO_BEST_MODT_FJL' => array('monograph', 'ill'),
                  'MUSIK_BEST_MODT' => array('music', 'local'),
                  'MUSIK_BEST_MODT_FJL' => array('music', 'ill'),
                  'AVIS_BEST_MODT' => array('newspaper', 'local', $avis),
                  'AVIS_BEST_MODT_FJL' => array('newspaper', 'ill', $avis_ill),
                  'PER_BEST_MODT' => array('journal', 'local', $per),
                  'PER_BEST_MODT_FJL' => array('journal', 'ill', $per_ill),
                  'VIDEO_BEST_MODT' => array('video', 'local'),
                  'VIDEO_BEST_MODT_FJL' => array('video', 'ill'));
              foreach ($m_fld as $vip_key => $res_key) {
                if (in_array($oa_row[$vip_key], array('J', 'B'))) {
                  Object::set_value($f, 'orderMaterialType', $res_key[0]);
                  Object::set_value($f, 'orderType', $res_key[1]);
                  if (is_array($res_key[2])) {
                    foreach ($res_key[2] as $elem_vip_key => $elem_res_key) {
                      if (in_array($oa_row[$elem_vip_key], array('J', 'O'))) {
                        Object::set_value($p, 'itemParameterType', $elem_res_key);
                        Object::set_value($p, 'parameterRequired', ($oa_row[$elem_vip_key] == 'O'? '1' : '0'));
                        Object::set_array_value($f, 'itemParameter', $p);
                        unset($p);
                      }
                    }
                  }
                  Object::set_array_value($usrOP, 'orderParameters', $f);
                  unset($f);
                }
              }
              foreach ($fjernadgang_rows as $fjern) {
                Object::set_value($f->_value, 'borrowerCheckSystem', $fjern['NAVN']);
                Object::set_value($f->_value, 'borrowerCheck', $fjern['FJERNADGANG_HAR_LAANERTJEK'] == 1 ? '1' : '0');
                $bCP[] = $f;
                unset($f);
              }
              $aP = new stdClass();
              $aP->borrowerCheckParameters = $bCP;
              Object::set_value($aP, 'acceptOrderFromUnknownUser', in_array($oa_row['BEST_UKENDT'], array('N', 'K'))? '1' : '0');
              if ($oa_row['BEST_UKENDT_TXT']) {
                $aP->acceptOrderFromUnknownUserText[] = self::value_and_language($oa_row['BEST_UKENDT_TXT'], 'dan');
              }
              if ($oa_row['BEST_UKENDT_TXT_ENG']) {
                $aP->acceptOrderFromUnknownUserText[] = self::value_and_language($oa_row['BEST_UKENDT_TXT_ENG'], 'eng');
              }
              Object::set_value($aP, 'acceptOrderAgencyOffline', $oa_row['LAANERTJEK_NORESPONSE'] == 'N' ? '0' : '1');
              Object::set_value($aP, 'payForPostage', $oa_row['PORTO_BETALING'] == 'N' ? '0' : '1');
              Object::set_value($usrOP, 'agencyParameters', $aP);
              /*      <open:userOrderParameters>
                      <!--1 or more repetitions:-->
                      <open:userParameter>
                      <open:userParameterType>?</open:userParameterType>
                      <open:parameterRequired>?</open:parameterRequired>
                      </open:userParameter>
                      <!--Zero or more repetitions:-->
                      <open:customIdTxt open:language="?">?</open:customIdTxt>
                      <!--Zero or more repetitions:-->
                      <open:userIdTxt open:language="?">?</open:userIdTxt>
                      <!--1 or more repetitions:-->
                      <open:orderParameters>
                      <open:orderMaterialType>?</open:orderMaterialType>
                      <open:orderType>?</open:orderType>
                      <!--1 or more repetitions:-->
                      <open:itemParameter>
                      <open:itemParameterType>?</open:itemParameterType>
                      <open:parameterRequired>?</open:parameterRequired>
                      </open:itemParameter>
                      </open:orderParameters>
                      <open:agencyParameters>
                      <!--Zero or more repetitions:-->
                      <open:borrowerCheckParameters>
                      <open:borrowerCheckSystem>?</open:borrowerCheckSystem>
                      <open:borrowerCheck>?</open:borrowerCheck>
                      </open:borrowerCheckParameters>
                      <open:acceptOrderFromUnknownUser>?</open:acceptOrderFromUnknownUser>
                      <!--0 to 2 repetitions:-->
                      <open:acceptOrderFromUnknownUserText open:language="?">?</open:acceptOrderFromUnknownUserText>
                      <open:acceptOrderAgencyOffline>?</open:acceptOrderAgencyOffline>
                      <open:payForPostage>?</open:payForPostage>
                      </open:agencyParameters>
                      </open:userOrderParameters>                        */

              //print_r($oa_row); 
              //var_dump($res); var_dump($param); die();
              break;
            default:
              Object::set_value($res, 'error', 'error_in_request');
          }
        }
      }
    }


    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'serviceResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch information about the library: name, address, ...
   *
   * Request:
   * - agencyId
   * - agencyName
   * - agencyAddress
   * - postalCode
   * - city
   * - stilNumber
   * - anyField
   * - libraryType
   * - libraryStatus
   * - pickupAllowed
   * - geolocation->latitude
   * - geolocation->longitude
   * - geolocation->longitude
   * - geolocation->distanceInMeter
   * - sort

   * Response:
   * - pickupAgency
   * - - agencyName
   * - - branchId
   * - - branchName
   * - - branchPhone
   * - - branchEmail
   * - - postalAddress
   * - - postalCode
   * - - city
   * - - isil
   * - - branchWebsiteUrl *
   * - - serviceDeclarationUrl *
   * - - registrationFormUrl *
   * - - paymentUrl *
   * - - userStatusUrl *
   * - - agencySubdivision
   * - - openingHours
   * - - temporarilyClosed
   * - - temporarilyClosedReason
   * - - pickupAllowed *
   * - - and more - see the xsd for all 
   * - or
   * - - error
   */
  public function findLibrary($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      if ($geoloc = $param->geolocation->_value) {
        $geo_cache = $geoloc->latitude->_value . '_' . $geoloc->longitude->_value . '_' . $geoloc->distanceInMeter->_value;
      }
      $cache_key = 'OA_FinL_' . 
        $this->config->get_inifile_hash() . 
        self::stringiefy($param->agencyId) . '_' . 
        self::stringiefy($param->agencyName) . '_' . 
        self::stringiefy($param->agencyAddress) . '_' . 
        self::stringiefy($param->postalCode) . '_' . 
        self::stringiefy($param->city) . '_' . 
        self::stringiefy($param->stilNumber) . '_' . 
        self::stringiefy($param->anyField) . '_' . 
        self::stringiefy($param->libraryType) . '_' . 
        self::stringiefy($param->libraryStatus) . '_' . 
        self::stringiefy($param->pickupAllowed) . '_' . 
        $geo_cache . '_' . 
        self::stringiefy($param->sort);
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      // agencyId
      if ($agency_id = self::strip_agency($param->agencyId->_value)) {
        $sqls[] = 'v.bib_nr = :bind_bib_nr';
        $oci->bind('bind_bib_nr', $agency_id);
      }
      // agencyName
      if ($val = $param->agencyName->_value) {
        $sqls[] = '(regexp_like(upper(v.navn), upper(:bind_navn))' .
            ' OR (regexp_like(upper(sup.tekst), upper(:bind_navn)) AND sup.type = :bind_n))';
        $oci->bind('bind_navn', self::build_regexp_like($val));
        $oci->bind('bind_n', 'N');
      }
      // agencyAddress
      if ($val = $param->agencyAddress->_value) {
        $sqls[] = '(regexp_like(upper(v.badr), upper(:bind_addr))' . 
            ' OR (regexp_like(upper(sup.tekst), upper(:bind_addr)) AND sup.type = :bind_a)' .
            ' OR regexp_like(upper(vsn.badr), upper(:bind_addr)))';
        $oci->bind('bind_addr', self::build_regexp_like($val));
        $oci->bind('bind_a', 'A');
      }
      // postalCode
      if ($val = $param->postalCode->_value) {
        $sqls[] = '(regexp_like(upper(v.bpostnr), upper(:bind_postnr))' .
            ' OR (regexp_like(upper(sup.tekst), upper(:bind_postnr)) AND sup.type = :bind_p))';
        $oci->bind('bind_postnr', self::build_regexp_like($val));
        $oci->bind('bind_p', 'P');
      }
      // city
      if ($val = $param->city->_value) {
        $sqls[] = 'regexp_like(upper(v.bcity), upper(:bind_city))';
        $oci->bind('bind_city', self::build_regexp_like($val));
      }
      // stilNumber
      if ($val = $param->stilNumber->_value) {
        $sqls[] = 'v.uni_c_nr = :bind_uni_c_nr';
        $oci->bind('bind_uni_c_nr', $val);
      }
      // anyField
      if ($val = $param->anyField->_value) {
        $bib_nr = self::strip_agency($param->anyField->_value);
        if (is_numeric($bib_nr) && (strlen($bib_nr) == 6)) {
          $oci->bind('bind_bib_nr', $bib_nr);
          $bibnr_sql = '(v.bib_nr = :bind_bib_nr) OR ';
        }
        $sqls[] = '(' . $bibnr_sql . 
          'regexp_like(upper(v.navn), upper(:bind_any))' .
          ' OR regexp_like(upper(v.badr), upper(:bind_any))' .
          ' OR regexp_like(upper(v.bpostnr), upper(:bind_any))' .
          ' OR regexp_like(upper(v.bcity), upper(:bind_any))' .
          ' OR (regexp_like(upper(sup.tekst), upper(:bind_any)) AND sup.type = :bind_n)' .
          ' OR (regexp_like(upper(sup.tekst), upper(:bind_any)) AND sup.type = :bind_a)' .
          ' OR (regexp_like(upper(sup.tekst), upper(:bind_any)) AND sup.type = :bind_p))';
        $oci->bind('bind_any', self::build_regexp_like($val));
        $oci->bind('bind_a', 'A');
        $oci->bind('bind_n', 'N');
        $oci->bind('bind_p', 'P');
      }
      // libraryType
      if ($val = $param->libraryType->_value
          && ($param->libraryType->_value == 'Folkebibliotek'
            || $param->libraryType->_value == 'Forskningsbibliotek'
            || $param->libraryType->_value == 'Skolebibliotek')) {
        $sqls[] = 'vsn.bib_type = :bind_bib_type';
        $oci->bind('bind_bib_type', $param->libraryType->_value);
      }
      elseif (empty($agency_id) && empty($param->stilNumber->_value)) {
        $sqls[] = 'vsn.bib_type != :bind_bib_type';
        $oci->bind('bind_bib_type', 'Skolebibliotek');
      }
      // libraryStatus
      if ($param->libraryStatus->_value == 'usynlig') {
        $oci->bind('bind_u', 'U');
        $sqls[] = 'v.delete_mark = :bind_u';
      } elseif ($param->libraryStatus->_value == 'slettet') {
        $oci->bind('bind_s', 'S');
        $sqls[] = 'v.delete_mark = :bind_s';
      } elseif ($param->libraryStatus->_value <> 'alle') {
        $sqls[] = 'v.delete_mark is null';
      }
      // pickupAllowed
      if (isset($param->pickupAllowed->_value)) {
        $j = 'J';
        $oci->bind('bind_j', $j);
        if (self::xs_boolean($param->pickupAllowed->_value)) {
          $sqls[] .= 'vb.best_modt = :bind_j';
        }
        else {
          $sqls[] .= 'vb.best_modt != :bind_j';
        }
      }
      $filter_sql = implode(' AND ', $sqls);

      // sort
      if (isset($param->sort)) {
        if (is_array($param->sort)) {
          $sorts = $param->sort;
        }
        else {
          $sorts = array($param->sort);
        }
        foreach ($sorts as $s) {
          switch ($s->_value) {
            case 'agencyId':      $sort_order[] = 'v.bib_nr'; break;
            case 'agencyName':    $sort_order[] = 'v.navn'; break;
            case 'agencyAddress': $sort_order[] = 'v.badr'; break;
            case 'postalCode':    $sort_order[] = 'v.postnr'; break;
            case 'city':          $sort_order[] = 'v.bcity'; break;
            case 'libraryType':   $sort_order[] = 'vsn.bib_type'; break;
          }
        }
        if ((count($sorts) == 1) && ($sorts[0]->_value == 'distance') && isset($geoloc->latitude->_value) && isset($geoloc->latitude->_value)) {
          if (!$distance = intval($geoloc->distanceInMeter->_value)) {
            $distance = 500000;
          }
          $sort_order[] = 'distance asc';
          $deg2rad = 0.0174532925;
          $rad2deg = 57.2957795;
          $deg2meter = 111045;
          $latitude = $geoloc->latitude->_value;
          $longitude = $geoloc->longitude->_value;
          // Flat earth society: 
          //$distance_sql = "$deg2meter * SQRT(POWER(v.latitude-$latitude,2) + POWER(v.longitude-$longitude,2)) distance, ";
          // Haversine: https://en.wikipedia.org/wiki/Haversine_formula
          $distance_sql = "$deg2meter * $rad2deg * (ACOS(COS($deg2rad*$latitude) * COS($deg2rad*v.latitude) * COS($deg2rad*($longitude-v.longitude)) + SIN($deg2rad*$latitude) * SIN($deg2rad*v.latitude))) distance, ";
        }
      }
      if (is_array($sort_order)) {
        $order_by = implode(', ', $sort_order);
      }
      else {
        $order_by = 'v.bib_nr';
      }

      $sql ='SELECT ' . $distance_sql . 'v.bib_nr, v.navn, v.navn_e, v.navn_k, v.navn_e_k, v.type, v.tlf_nr, v.email, v.badr, 
        v.bpostnr, v.bcity, v.isil, v.kmd_nr, v.url_homepage, v.url_payment, v.delete_mark,
        v.afsaetningsbibliotek, v.afsaetningsnavn_k, v.p_nr, v.uni_c_nr,
        TO_CHAR(v.dato, \'YYYY-MM-DD\') dato, TO_CHAR(v.bs_dato, \'YYYY-MM-DD\') bs_dato,
        v.latitude, v.longitude,
        vsn.navn vsn_navn, vsn.bib_nr vsn_bib_nr, vsn.bib_type vsn_bib_type,
        vsn.email vsn_email, vsn.tlf_nr vsn_tlf_nr, vsn.fax_nr vsn_fax_nr, 
        TO_CHAR(vsn.dato, \'YYYY-MM-DD\') vsn_dato, vsn.oclc_symbol, vsn.sb_kopibestil,
        vsn.cvr_nr vsn_cvr_nr, vsn.p_nr vsn_p_nr, vsn.ean_nummer vsn_ean_nummer,
        vb.best_modt, vb.best_modt_luk, vb.best_modt_luk_eng,
        txt.aabn_tid, txt.kvt_tekst_fjl, eng.aabn_tid_e, eng.kvt_tekst_fjl_e, hold.holdeplads,
        bestil.url_serv_dkl, bestil.support_email, bestil.support_tlf, bestil.ncip_address, bestil.ncip_password,
        kat.url_best_blanket, kat.url_best_blanket_text, kat.url_laanerstatus, kat.ncip_lookup_user,
        kat.ncip_renew, kat.ncip_cancel, kat.ncip_update_request, kat.filial_vsn, 
        kat.url_viderestil, kat.url_bib_kat
          FROM vip v, vip_vsn vsn, vip_beh vb, vip_txt txt, vip_txt_eng eng, vip_sup sup,
        vip_bogbus_holdeplads hold, vip_bestil bestil, vip_kat kat
          WHERE 
          ' . $filter_sql . '
          AND v.kmd_nr = vsn.bib_nr (+)
          AND v.bib_nr = vb.bib_nr (+)
          AND v.bib_nr = sup.bib_nr (+)
          AND v.bib_nr = txt.bib_nr (+)
          AND v.bib_nr = hold.bib_nr (+)
          AND v.bib_nr = eng.bib_nr (+)
          AND v.bib_nr = bestil.bib_nr (+)
          AND v.bib_nr = kat.bib_nr (+)
          ORDER BY ' . $order_by;
      //var_dump($geoloc); var_dump($sorts); var_dump($distance_sql); die($sql);
      $this->watch->start('sql1');
      try {
        $this->watch->start('sql1');
        $oci->set_query($sql);
        $this->watch->stop('sql1');
        while ($row = $oci->fetch_into_assoc()) {
          if (empty($curr_bib)) {
            $curr_bib = $row['BIB_NR'];
          }
          if ($curr_bib <> $row['BIB_NR']) {
            if ($pickupAgency)
              Object::set_array_value($res, 'pickupAgency', $pickupAgency);
            unset($pickupAgency);
            $curr_bib = $row['BIB_NR'];
          }
          if ($row && (empty($distance_sql) || ($row['DISTANCE'] && ($row['DISTANCE'] <= $distance)))) {
            //$row['NAVN'] = $row['VSN_NAVN'];
            self::fill_pickupAgency($pickupAgency, $row);
          }
        }
        if ($pickupAgency)
          Object::set_array_value($res, 'pickupAgency', $pickupAgency);
      }
      catch (ociException $e) {
        $this->watch->stop('sql1');
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
        Object::set_value($res, 'error', 'service_unavailable');
      }
      $this->watch->stop('sql1');
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'findLibraryResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief Fetch Rules for a given library
   *
   * Request:
   * - agencyId
   * Response:
   * - libraryRules
   * or
   * - error
   */
  public function libraryRules($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $cache_key = 'OA_libRu_' . $this->config->get_inifile_hash() . $param->agencyId->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        $agency = self::strip_agency($param->agencyId->_value);
        if (empty($agency) && !empty($param->agencyId->_value)) {
          Object::set_value($res, 'error', 'agency_not_found');
        }
        else {
          try {
            if ($agency) {
              $oci->bind('bind_bib_nr', $agency);
              $where = ' WHERE bib_nr = :bind_bib_nr';
            }
            $this->watch->start('sql1');
            $oci->set_query('SELECT * FROM vip_library_rules' . $where . ' ORDER BY bib_nr ASC');
            $this->watch->stop('sql1');
            //$mem = memory_get_usage();
            while ($row = $oci->fetch_into_assoc()) {
              Object::set_value($o, 'agencyId', self::normalize_agency($row['BIB_NR']));
              foreach ($row as $name => $value) {
                if ($name != 'BIB_NR') {
                  Object::set_value($r, 'name', strtolower($name));
                  Object::set_value($r, 'bool', ($value == 'Y' ? '1' : '0'));
                  Object::set_array_value($o, 'libraryRule', $r);
                  unset($r);
                }
              }
              Object::set_array_value($res, 'libraryRules', $o);
              unset($o);
            }
            //$this->watch->sums['mem'] = memory_get_usage() - $mem;
          }
          catch (ociException $e) {
            $this->watch->stop('sql1');
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            Object::set_value($res, 'error', 'service_unavailable');
          }
        }
      }
    }
    Object::set_value($ret, 'libraryRulesResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief Fetch a list of library types
   *
   * Request:
   * Response:
   * - libraryType
   * or
   * - error
   */
  public function libraryTypeList($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $cache_key = 'OA_libTL_' . $this->config->get_inifile_hash() . $param->libraryType->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
          $oci->bind('bind_u', 'U');
          $this->watch->start('sql1');
          $oci->set_query('SELECT vsn.bib_nr vsn_bib_nr, vsn.bib_type, v.bib_nr, v.type
              FROM vip v, vip_vsn vsn
              WHERE v.kmd_nr = vsn.bib_nr
              AND (v.delete_mark is null OR v.delete_mark = :bind_u)
              ORDER BY bib_nr');
          $this->watch->stop('sql1');
//$mem = memory_get_usage();
          while ($row = $oci->fetch_into_assoc()) {
            Object::set_value($o, 'agencyId', self::normalize_agency($row['VSN_BIB_NR']));
            Object::set_value($o, 'agencyType', $row['BIB_TYPE']);
            Object::set_value($o, 'branchId', self::normalize_agency($row['BIB_NR']));
            Object::set_value($o, 'branchType', $row['TYPE']);
            Object::set_array_value($res, 'libraryTypeInfo', $o);
            unset($o);
          }
//$this->watch->sums['mem'] = memory_get_usage() - $mem;
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
      $this->watch->stop('sql');
    }
    Object::set_value($ret, 'libraryTypeListResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief Fetch a list of libraries
   *
   * Request:
   * - libraryType
   * Response:
   * - agency (see xsd for parameters)
   * or
   * - error
   */
  public function nameList($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      //var_dump($this->aaa->get_rights()); die();
      $cache_key = 'OA_namL_' . $this->config->get_inifile_hash() . $param->libraryType->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        if ($param->libraryType->_value == 'Alle' ||
            $param->libraryType->_value == 'Folkebibliotek' ||
            $param->libraryType->_value == 'Forskningsbibliotek' ||
            $param->libraryType->_value == 'Skolebibliotek') {
          try {
            if ($param->libraryType->_value <> 'Alle') {
              $filter_bib_type = 'AND vsn.bib_type = :bind_bib_type';
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
            $oci->bind('bind_u', 'U');
            $this->watch->start('sql1');
            $oci->set_query('SELECT vsn.bib_nr, vsn.navn
                FROM vip v, vip_vsn vsn
                WHERE v.bib_nr = vsn.bib_nr
                AND (v.delete_mark is null OR v.delete_mark = :bind_u) ' . $filter_bib_type);
            while ($vv_row = $oci->fetch_into_assoc()) {
              Object::set_value($o, 'agencyId', self::normalize_agency($vv_row['BIB_NR']));
              Object::set_value($o, 'agencyName', $vv_row['NAVN']);
              Object::set_array_value($res, 'agency', $o);
              unset($o);
            }
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            Object::set_value($res, 'error', 'service_unavailable');
          }
          $this->watch->stop('sql1');
        }
        else
          Object::set_value($res, 'error', 'error_in_request');
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'nameListResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch information about pickupAgency
   *
   * Request:
   * - agencyId
   * - agencyName
   * - agencyAddress
   * - postalCode
   * - city
   * - libraryType
   * - libraryStatus
   * - pickupAllowed

   * Response:
   * - library
   * - - agencyId
   * - - agencyName
   * - - agencyPhone
   * - - agencyEmail
   * - - postalAddress
   * - - postalCode
   * - - city
   * - - agencyWebsiteUrl *
   * - - pickupAgency
   * - - - branchId
   * - - - branchName
   * - - - branchPhone
   * - - - branchEmail
   * - - - postalAddress
   * - - - postalCode
   * - - - city
   * - - - isil
   * - - - branchWebsiteUrl *
   * - - - serviceDeclarationUrl *
   * - - - registrationFormUrl *
   * - - - paymentUrl *
   * - - - userStatusUrl *
   * - - - agencySubdivision
   * - - - openingHours
   * - - - temporarilyClosed
   * - - - temporarilyClosedReason
   * - - - pickupAllowed *
   * - or
   * - - error
   */
  public function pickupAgencyList($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      foreach (array('agencyId', 'agencyName', 'agencyAddress', 'postalCode', 'city', 'anyField') as $par) {
        if (is_array($param->$par)) {
          foreach ($param->$par as $p) {
            if ($par == 'agencyId') {
              $ag = self::strip_agency($p->_value);
              if ($ag)
                $ora_par[$par][] = $ag;
              $param_agencies[$ag] = $p->_value;
            }
            elseif ($p->_value) {
              $ora_par[$par][] = $p->_value;
            }
          }
        }
        elseif ($param->$par) {
          if ($par == 'agencyId') {
            $ag = self::strip_agency($param->$par->_value);
            if ($ag)
              $ora_par[$par][] = $ag;
            $param_agencies[$ag] = $param->$par->_value;
          }
          elseif ($param->$par->_value) {
            $ora_par[$par][] = $param->$par->_value;
          }
        }
      }
      $cache_key = 'OA_picAL_' . 
        $this->config->get_inifile_hash() . 
        (is_array($ora_par['agencyId']) ? implode('', $ora_par['agencyId']) : '') . 
        (is_array($ora_par['agencyName']) ? implode('', $ora_par['agencyName']) : '') . 
        (is_array($ora_par['agencyAddress']) ? implode('', $ora_par['agencyAddress']) : '') . 
        (is_array($ora_par['postalCode']) ? implode('', $ora_par['postalCode']) : '') . 
        (is_array($ora_par['city']) ? implode('', $ora_par['city']) : '') . 
        $param->pickupAllowed->_value . 
        $param->libraryStatus->_value . 
        $param->libraryType->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        if (is_array($ora_par) ||
            $param->libraryType->_value == 'Alle' ||
            $param->libraryType->_value == 'Folkebibliotek' ||
            $param->libraryType->_value == 'Forskningsbibliotek' ||
            $param->libraryType->_value == 'Skolebibliotek') {
          try {
            if ($ora_par) {
              foreach ($ora_par as $key => $val) {
                $add_sql = '';
                foreach ($val as $par) {
                  $add_item = '';
                  switch ($key) {
                    case 'agencyId':
                      break;
                    case 'agencyAddress':
                      $add_item .= "upper(v.badr) like upper('%$par%')";
                      break;
                    case 'agencyName':
                      $add_item .= "upper(v.navn) like upper('%$par%')";
                      break;
                    case 'city':
                      $add_item .= "upper(v.bcity) like upper('%$par%')";
                      break;
                    case 'postalCode':
                      $add_item .= "upper(v.bpostnr) like upper('%$par%')";
                      break;
                  }
                  if ($add_item) {
                    $add_sql .= (empty($add_sql) ? " (" : ' OR ' ) . $add_item;
                  }
                }
                if ($add_sql) $filter_bib_type[] = $add_sql . ")";
              }
            }
            if ($ora_par['agencyId']) {
              foreach ($ora_par['agencyId'] as $agency) {
                $agency_list .= ($agency_list ? ', ' : '') . ':bind_' . $agency;
                $oci->bind('bind_' . $agency, $agency);
              }
              $filter_bib_type[] = " v.bib_nr IN ($agency_list)";
            }
            elseif (empty($ora_par) && $param->libraryType->_value <> 'Alle') {
              $filter_bib_type[] = ' vsn.bib_type = :bind_bib_type';
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
            else {
              $filter_bib_type[] = ' vsn.bib_type != :bind_bib_type';
              $oci->bind('bind_bib_type', 'Skolebibliotek');
            }
            // 2do vip_beh.best_modt = $param->pickupAllowed->_value
            if ($param->libraryStatus->_value == 'alle') {
              $filter_delete_vsn = '';
            } elseif ($param->libraryStatus->_value == 'usynlig') {
              $oci->bind('bind_u', 'U');
              $filter_delete_vsn = 'v.delete_mark = :bind_u AND ';
            } elseif ($param->libraryStatus->_value == 'slettet') {
              $oci->bind('bind_s', 'S');
              $filter_delete_vsn = 'v.delete_mark = :bind_s AND ';
            } else {
              $filter_delete_vsn = 'v.delete_mark is null AND ';
            }
            $sql = 'SELECT vsn.bib_nr, vsn.navn, vsn.bib_type, vsn.tlf_nr, vsn.email,
              vsn.badr, vsn.bpostnr, vsn.bcity, vsn.url, vsn.sb_kopibestil,
              vsn.cvr_nr, vsn.p_nr, vsn.ean_nummer
                FROM vip_vsn vsn, vip v, vip_sup vs
                WHERE ' . $filter_delete_vsn . ($filter_bib_type ? implode(' AND ', $filter_bib_type) . ' AND ' : '') . '
                v.bib_nr = vs.bib_nr (+)
                AND v.kmd_nr = vsn.bib_nr
                ORDER BY vsn.bib_nr';
            $this->watch->start('sql1');
            $oci->set_query($sql);
            while ($row = $oci->fetch_into_assoc()) {
              $bib_nr = &$row['BIB_NR'];
              $vsn[$bib_nr] = $row;
            }
            $this->watch->stop('sql1');

            $sql = 'SELECT unique bib_nr, domain FROM user_domains WHERE DELETE_DATE IS NULL';
            $this->watch->start('sql2');
            $oci->set_query($sql);
            while ($row = $oci->fetch_into_assoc()) {
              $ip_list[$row['BIB_NR']][] = $row['DOMAIN'];
            }
            $this->watch->stop('sql2');

            if ($ora_par['agencyId']) {
              foreach ($ora_par['agencyId'] as $agency) {
                $oci->bind('bind_' . $agency, $agency);
              }
            }
            elseif (empty($ora_par) && $param->libraryType->_value <> 'Alle') {
              $oci->bind('bind_bib_type', $param->libraryType->_value);
            }
            else {
              $oci->bind('bind_bib_type', 'Skolebibliotek');
            }
            if (isset($param->pickupAllowed->_value)) {
              $oci->bind('bind_j', 'J');
              $filter_bib_type[] = 'vb.best_modt ' . (self::xs_boolean($param->pickupAllowed->_value) ? '=' : '!=') . ':bind_j';
            }
            if ($param->libraryStatus->_value == 'alle') {
              $filter_delete = '';
            } elseif ($param->libraryStatus->_value == 'usynlig') {
              $oci->bind('bind_u', 'U');
              $filter_delete = ' AND v.delete_mark = :bind_u';
            } elseif ($param->libraryStatus->_value == 'slettet') {
              $oci->bind('bind_s', 'S');
              $filter_delete = ' AND v.delete_mark = :bind_s';
            } else {
              $filter_delete = ' AND v.delete_mark is null';
            }
            if ($filter_delete) {
              $oci->bind('bind_n', 'N');
              $filter_filial = ' AND (vb.filial_tf <> :bind_n OR vb.filial_tf is null)';
            }
            $sql ='SELECT v.bib_nr, v.navn, v.navn_e, v.navn_k, v.navn_e_k, v.type, v.tlf_nr, v.email, v.badr, 
              v.bpostnr, v.bcity, v.isil, v.kmd_nr, v.url_homepage, v.url_payment, v.delete_mark, v.p_nr, v.uni_c_nr, 
              vb.best_modt, vb.best_modt_luk, vb.best_modt_luk_eng,
              vd.svar_email,
              txt.aabn_tid, txt.kvt_tekst_fjl, 
              eng.aabn_tid_e, eng.kvt_tekst_fjl_e, 
              hold.holdeplads,
              bestil.url_serv_dkl, bestil.support_email, bestil.support_tlf,
              kat.url_best_blanket, kat.url_best_blanket_text, kat.url_laanerstatus, kat.ncip_lookup_user, kat.ncip_renew, 
              kat.ncip_cancel, kat.ncip_update_request, kat.filial_vsn, kat.url_viderestil, kat.url_bib_kat
                FROM vip v, vip_beh vb, vip_danbib vd, vip_txt txt, vip_txt_eng eng, 
              vip_bogbus_holdeplads hold, vip_bestil bestil, vip_kat kat
                WHERE v.kmd_nr IN (SELECT UNIQUE vsn.bib_nr
                    FROM vip_vsn vsn, vip v, vip_sup vs
                    WHERE ' . $filter_delete_vsn . '
                    v.kmd_nr = vsn.bib_nr ' .
                    ($filter_bib_type ? ' AND ' . implode(' AND ', $filter_bib_type) : '') . ')
                ' . $filter_delete . '
                ' . $filter_filial . '
                AND v.bib_nr = vb.bib_nr (+)
                AND v.bib_nr = vd.bib_nr (+)
                AND v.bib_nr = txt.bib_nr (+)
                AND v.bib_nr = hold.bib_nr (+)
                AND v.bib_nr = eng.bib_nr (+)
                AND v.bib_nr = bestil.bib_nr (+)
                AND v.bib_nr = kat.bib_nr (+)
                ORDER BY v.kmd_nr, v.bib_nr';
            $this->watch->start('sql3');
            $oci->set_query($sql);
            $this->watch->stop('sql3');
            while ($row = $oci->fetch_into_assoc()) {
              if ($ora_par['agencyId']) {
                $a_key = array_search($row['BIB_NR'], $ora_par['agencyId']);
                if (is_int($a_key)) unset($ora_par['agencyId'][$a_key]);
              }
              $this_vsn = $row['KMD_NR'];
              if ($library && $library->agencyId->_value <> $this_vsn) {
                Object::set_array_value($library, 'pickupAgency', $pickupAgency);
                unset($pickupAgency);
                Object::set_array_value($res, 'library', $library);
                unset($library);
              }
              if (empty($library)) {
                Object::set_value($library, 'agencyId', $this_vsn);
                Object::set_value($library, 'agencyType', self::set_agency_type($this_vsn, $vsn[$this_vsn]['BIB_TYPE']));
                Object::set_value($library, 'agencyName', $vsn[$this_vsn]['NAVN']);
                if ($vsn[$this_vsn]['TLF_NR']) Object::set_value($library, 'agencyPhone', $vsn[$this_vsn]['TLF_NR']);
                if ($vsn[$this_vsn]['EMAIL']) Object::set_value($library, 'agencyEmail', $vsn[$this_vsn]['EMAIL']);
                if ($vsn[$this_vsn]['BADR']) Object::set_value($library, 'postalAddress', $vsn[$this_vsn]['BADR']);
                if ($vsn[$this_vsn]['BPOSTNR']) Object::set_value($library, 'postalCode', $vsn[$this_vsn]['BPOSTNR']);
                if ($vsn[$this_vsn]['BCITY']) Object::set_value($library, 'city', $vsn[$this_vsn]['BCITY']);
                if ($vsn[$this_vsn]['URL']) Object::set_value($library, 'agencyWebsiteUrl', $vsn[$this_vsn]['URL']);
                if ($vsn[$this_vsn]['CVR_NR']) Object::set_value($library, 'agencyCvrNumber', $vsn[$this_vsn]['CVR_NR']);
                if ($vsn[$this_vsn]['P_NR']) Object::set_value($library, 'agencyPNumber', $vsn[$this_vsn]['P_NR']);
                if ($vsn[$this_vsn]['EAN_NUMMER']) Object::set_value($library, 'agencyEanNumber', $vsn[$this_vsn]['EAN_NUMMER']);
              }
              if ($pickupAgency && $pickupAgency->branchId->_value <> $row['BIB_NR']) {
                Object::set_array_value($library, 'pickupAgency', $pickupAgency);
                unset($pickupAgency);
              }
              $row['SB_KOPIBESTIL'] = $vsn[$this_vsn]['SB_KOPIBESTIL'];
              self::fill_pickupAgency($pickupAgency, $row, $ip_list[$row['BIB_NR']]);
            }
            if ($pickupAgency) {
              Object::set_array_value($library, 'pickupAgency', $pickupAgency);
            }
            if ($library) {
              Object::set_array_value($res, 'library', $library);
              if ($ora_par['agencyId']) {
                foreach ($ora_par['agencyId'] as $agency) {
                  Object::set_value($help, 'agencyId', $param_agencies[$agency]);
                  Object::set_value($help, 'error', 'agency_not_found');
                  Object::set_array_value($res, 'library', $help);
                  unset($help);
                }
              }
            } else {
              Object::set_value($res, 'error', 'no_agencies_found');
            }
          }
          catch (ociException $e) {
            $this->watch->stop('sql1');
            $this->watch->stop('sql2');
            $this->watch->stop('sql3');
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            Object::set_value($res, 'error', 'service_unavailable');
          }
        }
        else
          Object::set_value($res, 'error', 'error_in_request');
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'pickupAgencyListResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch search profiles for the openSearch service
   *
   * Request:
   * - agencyId
   * - profileName: 
   * - profileVersion: 
   * Response:
   * - profile
   * - - profileName
   * - - source
   * - - - sourceName
   * - - - sourceIdentifier
   * - - - 1 above or 2 below
   * - - - sourceOwner
   * - - - sourceFormat
   * - - - relation
   * - - - - rdfLabel
   * - - - - rdfInverse
   */
  public function openSearchProfile($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_opeSP_' . $this->config->get_inifile_hash() . $agency . $param->profileName->_value . $param->profileVersion->_value;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        if ($param->profileVersion->_value == 3) {
          try {
            // hent alle broend_to_kilder med searchable == "Y" og loop over dem.
            // find de kilder som profilen kender:
            // Søgbarheden (sourceSearchable) gives hvis den findes i den givne profil (broendkilde_id findes)
            // Søgbargeden kan evt. begrænses af broend_to_kilder.access_for
            $oci->bind('bind_y', 'Y');
            $this->watch->start('sql1');
            $oci->set_query('SELECT DISTINCT *
                FROM broend_to_kilder
                WHERE searchable = :bind_y
                ORDER BY upper(name)');
            $kilder = $oci->fetch_all_into_assoc();
            $this->watch->stop('sql1');
            $oci->bind('bind_agency', $agency);
            if ($profile = strtolower($param->profileName->_value)) {
              $oci->bind('bind_profile', $profile);
              $sql_add = ' AND lower(broend_to_profiler.name) = :bind_profile';
            }
            $this->watch->start('sql2');
            $oci->set_query('SELECT broendkilde_id, profil_id, name
                FROM broendprofil_to_kilder, broend_to_profiler
                WHERE broend_to_profiler.bib_nr = :bind_agency
                AND broendprofil_to_kilder.broendkilde_id IS NOT NULL
                AND broendprofil_to_kilder.profil_id IS NOT NULL
                AND broend_to_profiler.id_nr = broendprofil_to_kilder.profil_id (+)' . $sql_add);
            $profil_res = $oci->fetch_all_into_assoc();
            $this->watch->stop('sql2');
            $profiler = array();
            foreach ($profil_res as $p) {
              if ($p['PROFIL_ID'] && $p['BROENDKILDE_ID']) {
                $profiler[$p['PROFIL_ID']][$p['BROENDKILDE_ID']] = $p;
              }
            }
            foreach ($profiler as $profil_no => $profil) {
              foreach ($kilder as $kilde) {
                if (empty($kilde['ACCESS_FOR']) || strpos($kilde['ACCESS_FOR'], $agency) !== FALSE) {
                  $oci->bind('bind_kilde_id', $kilde['ID_NR']);
                  $oci->bind('bind_profil_id', $profil_no);
                  $this->watch->start('sql3');
                  $oci->set_query('SELECT DISTINCT rdf, rdf_reverse
                      FROM broend_relation, broend_kilde_relation, broend_profil_kilde_relation
                      WHERE broend_kilde_relation.broendkilde_id = :bind_kilde_id 
                      AND broend_profil_kilde_relation.profil_id = :bind_profil_id 
                      AND broend_profil_kilde_relation.kilde_relation_id =  broend_kilde_relation.id_nr 
                      AND broend_kilde_relation.relation_id = broend_relation.id_nr');
                  $relations = $oci->fetch_all_into_assoc();
                  $this->watch->stop('sql3');
                  Object::set_value($s, 'sourceName', $kilde['NAME']);
                  if (isset($profil[$kilde['ID_NR']])) {
                    $profile_name = $profil[$kilde['ID_NR']]['NAME'];
                    Object::set_value($s, 'sourceSearchable', '1');
                  }
                  else
                    Object::set_value($s, 'sourceSearchable', '0');
                  if ($kilde['CONTAINED_IN']) {
                    Object::set_value($s, 'sourceContainedIn', $kilde['CONTAINED_IN']);
                  }
                  Object::set_value($s, 'sourceIdentifier', str_replace('[agency]', $agency, $kilde['IDENTIFIER']));
                  if ($relations) {
                    foreach ($relations as $relation) {
                      Object::set_value($rel, 'rdfLabel', $relation['RDF']);
                      if ($relation['RDF_REVERSE'])
                        Object::set_value($rel, 'rdfInverse', $relation['RDF_REVERSE']);
                      Object::set_array_value($s, 'relation', $rel);
                      unset($rel);
                    }
                  }
                }
                Object::set_value($res->profile[$profil_no]->_value, 'profileName', $profile_name);
                if ($s) {
                  Object::set_array_value($res->profile[$profil_no]->_value, 'source', $s);
                  unset($s);
                }
              }
            }
          }
          catch (ociException $e) {
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            Object::set_value($res, 'error', 'service_unavailable');
          }
        } else {
          $oci->bind('bind_agency', $agency);
          if ($profile = strtolower($param->profileName->_value)) {
            $oci->bind('bind_profile', $profile);
            $sql_add = ' AND lower(broendprofiler.name) = :bind_profile';
          }
          try {
            $this->watch->start('sql4');
            $oci->set_query('SELECT DISTINCT broendprofiler.name bp_name,
                broendkilder.name, submitter, format
                FROM broendkilder, broendprofil_kilder, broendprofiler
                WHERE broendkilder.id_nr = broendprofil_kilder.broendkilde_id
                AND broendprofil_kilder.profil_id = broendprofiler.id_nr
                AND broendprofiler.bib_nr = :bind_agency' . $sql_add);
            $this->watch->stop('sql4');
            while ($s_row = $oci->fetch_into_assoc()) {
              Object::set_value($s, 'sourceName', $s_row['NAME']);
              Object::set_value($s, 'sourceOwner', (strtolower($s_row['SUBMITTER']) == 'agency' ? $agency : $s_row['SUBMITTER']));
              Object::set_value($s, 'sourceFormat', $s_row['FORMAT']);
              Object::set_value($res->profile[$s_row['BP_NAME']]->_value, 'profileName', $s_row['BP_NAME']);
              Object::set_array_value($res->profile[$s_row['BP_NAME']]->_value, 'source', $s);
              unset($s);
            }
          }
          catch (ociException $e) {
            $this->watch->stop('sql1');
            $this->watch->stop('sql2');
            $this->watch->stop('sql3');
            $this->watch->stop('sql4');
            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
            Object::set_value($res, 'error', 'service_unavailable');
          }
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'openSearchProfileResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch information about remote access 
   *
   * Request:
   * - agencyId
   * Response:
   * - agencyId
   * - subscription
   * - - name
   * - - url
   * - or
   * - - error
   */
  public function remoteAccess($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 550))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_remA_' . $this->config->get_inifile_hash() . $agency;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
      if (empty($res->error)) {
        try {
          $oci->bind('bind_agency', $agency);
          $uno = '1';
          $oci->bind('bind_har_adgang', $uno);
          $this->watch->start('sql1');
          $oci->set_query('SELECT fjernadgang_licenser.navn "licens_navn",
              fjernadgang_licenser.url "licens_url",
              fjernadgang_dbc.navn "dbc_navn",
              fjernadgang_dbc.url "dbc_url",
              fjernadgang_dbc.har_fjernadgang "dbc_har_fjernadgang",
              fjernadgang_andre.navn "andre_navn",
              fjernadgang_andre.url "andre_url",
              fjernadgang_andre.har_fjernadgang "andre_har_fjernadgang",
              fjernadgang.har_adgang,
              fjernadgang.faust,
              fjernadgang.url,
              autolink
              FROM fjernadgang, fjernadgang_licenser, fjernadgang_dbc, fjernadgang_andre, licensguide
              WHERE fjernadgang.bib_nr = :bind_agency
              AND fjernadgang.type = :bind_har_adgang
              AND fjernadgang.faust = fjernadgang_licenser.faust (+)
              AND fjernadgang.faust = fjernadgang_dbc.faust (+)
              AND fjernadgang.faust = fjernadgang_andre.faust (+)
              AND fjernadgang.bib_nr = licensguide.bib_nr (+)');
          $buf = $oci->fetch_all_into_assoc();
          $this->watch->stop('sql1');
          Object::set_value($res, 'agencyId', $param->agencyId->_value);
          foreach ($buf as $val) {
            if ($help = $val['licens_navn']) {
              Object::set_value($s, 'name', $help);
              if ($val['AUTOLINK']) {
                Object::set_value($s, 'url', $val['AUTOLINK']);
              }
              else {
                Object::set_value($s, 'url', ($val['URL'] ? $val['URL'] : $val['licens_url']));
              }
            }
            elseif ($help = $val['dbc_navn']) {
              Object::set_value($s, 'name', $help);
              Object::set_value($s, 'url', ($val['URL'] ? $val['URL'] : $val['dbc_url']));
            }
            elseif ($help = $val['andre_navn']) {
              Object::set_value($s, 'name', $help);
              Object::set_value($s, 'url', ($val['URL'] ? $val['URL'] : $val['andre_url']));
            }
            if ($s->url->_value && ($val['FAUST'] <> 1234567)) {    // drop eBib
              if ($val['URL'])
                Object::set_value($s, 'url', str_replace('[URL_FJERNADGANG]', $val['URL'], $s->url->_value));
              else
                Object::set_value($s, 'url', str_replace('[URL_FJERNADGANG]', $val['licens_url'], $s->url->_value));
              Object::set_value($s, 'url', str_replace('[LICENS_ID]', $val['FAUST'], $s->url->_value));
              Object::set_array_value($res, 'subscription', $s);
            }
            unset($s);
          }
        }
        catch (ociException $e) {
          $this->watch->stop('sql1');
          verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          Object::set_value($res, 'error', 'service_unavailable');
        }
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'remoteAccessResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /** \brief Fetch an ordered sequence of agencies to use when ordering materials
   *
   * Request:
   * - agencyId
   * Response:
   * - agencyId's
   * or
   * - error
   */
  public function requestOrder($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_reqO_' . $this->config->get_inifile_hash() . $agency;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $this->watch->start('entry');
      $res = self::get_prioritized_agency_list($agency, 'laaneveje');
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'requestOrderResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }

  /** \brief Fetch an ordered sequence of agencies to use when selecting records to show
   * default to use 870970 if no list exists for the actual agency
   *
   * Request:
   * - agencyId
   * Response:
   * - agencyId's
   * or
   * - error
   */
  public function showOrder($param) {
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      Object::set_value($res, 'error', 'authentication_error');
    else {
      $agency = self::strip_agency($param->agencyId->_value);
      $cache_key = 'OA_shoO_' . $this->config->get_inifile_hash() . $agency;
      self::set_cache_expire($this->cache_expire[__FUNCTION__]);
      if ($ret = $this->cache->get($cache_key)) {
        verbose::log(STAT, 'Cache hit');
        return $ret;
      }
      $res = self::get_prioritized_agency_list($agency, 'visprioritet');
      if ($res->error->_value == 'no_agencies_found') {
        $res = self::get_prioritized_agency_list('870970', 'visprioritet');
      }
    }
    //var_dump($res); var_dump($param); die();
    Object::set_value($ret, 'showOrderResponse', $res);
    $ret = $this->objconvert->set_obj_namespace($ret, $this->xmlns['oa']);
    if (empty($res->error)) $this->cache->set($cache_key, $ret);
    $this->watch->stop('entry');
    return $ret;
  }


  /* ----------------------------------------------------------------------------- */


  /** \brief get a oci connection
   *
   * @param string $credentials 
   * @param object $error 
   * @retval object  - oci object
   */
  private function connect($credentials, $line, &$error) {
    $oci = new Oci($credentials);
    $oci->set_charset('UTF8');
    $this->watch->start('connect');
    try {
      $oci->connect();
    }
    catch (ociException $e) {
      verbose::log(FATAL, 'OpenAgency('. $line .'):: OCI connect error: ' . $oci->get_error_string());
      Object::set_value($error, 'error', 'service_unavailable');
    }
    $this->watch->stop('connect');
    return $oci;
  }

  /** \brief change 18626 to iso18626 and leaves rest unchanged
   *
   * @param string $protocol 
   * @retval string 
   */
  private function normalize_iso18626($protocol) {
    return ($protocol == '18626' ? 'iso18626' : $protocol);
  }

  /** \brief get a priority list from some table
   *
   * @param string $agency 
   * @param string $table_name - must contain columns: bibliotek, vilse and prionr
   * @retval array - of agencies
   */
  private function get_prioritized_agency_list($agency, $table_name) {
    $oci = self::connect($this->config->get_value('agency_credentials','setup'), __LINE__, $res);
    if (empty($res->error)) {
      try {
        $oci->bind('bind_agency', $agency);
        $this->watch->start('sql1');
        $oci->set_query('SELECT vilse 
            FROM vip, ' . $table_name . '
            WHERE (vip.kmd_nr = bibliotek OR vip.bib_nr = bibliotek)
            AND vip.bib_nr = :bind_agency
            ORDER BY prionr DESC');
        $this->watch->stop('sql1');
        $prio = array();
        while ($s_row = $oci->fetch_into_assoc()) {
          Object::set_array_value($res, 'agencyId', $s_row['VILSE']);
        }
        if (empty($res->agencyId)) {
          Object::set_value($res, 'error', 'no_agencies_found');
        }
      }
      catch (ociException $e) {
        $this->watch->stop('sql1');
        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
        Object::set_value($res, 'error', 'service_unavailable');
      }
    }
    return $res;
  }

  /** \brief overwrite z-target informations
   *
   * @param row (array) - one result array from the DB, adjusted to DBC settings
   * @param dbc_z3950 (array) - DBC settings for z3950
   * @param auth (object) - Authentication object of the client
   */
  private function use_dbc_as_z3950_target(&$row, $dbc_z3950, $auth) {
    $row['URL_ITEMORDER_BESTIL'] = $dbc_z3950['address'];
    $row['ZBESTIL_GROUPID'] = $auth->groupIdAut->_value;;
    $row['ZBESTIL_USERID'] = $auth->userIdAut->_value;;
    $row['ZBESTIL_PASSW'] = $auth->passwordAut->_value;;
  }

  /** \brief overwrite iso18626 informations
   *
   * @param row (array) - one result array from the DB, adjusted to DBC settings
   * @param dbc_iso18626 (array) - DBC settings for iso18626
   */
  private function use_dbc_as_iso18626_target(&$row, $dbc_iso18626) {
    $row['ISO18626_ADDRESS'] = $dbc_iso18626['address'];
    $row['ISO18626_PASSWORD'] = $dbc_iso18626['password'];
  }

  /** \brief add iso18626 protocol info to result
   *
   * @param buf (object) - structure for the result
   * @param row (array) - one result array from the DB
   */
  private function fill_iso18626_protocol(&$buf, $row) {
    Object::set_value($buf, 'willReceive', 'YES');
    Object::set_value($buf, 'synchronous', 0);
    Object::set_value($buf, 'protocol', 'iso18626');
    Object::set_value($buf, 'address', $row['ISO18626_ADDRESS']);
    Object::set_value($buf, 'passWord', $row['ISO18626_PASSWORD']);
  }

  /** \brief add iso18626 data to result
   *
   * @param buf (object) - structure for the result
   * @param row (array) - one result array from the DB
   */
  private function set_iso18626(&$buf, $row) {
    if ($row['ISO18626_ADDRESS'] || $row['ISO18626_PASSWORD']) {
      $val = &$buf->iso18626->_value;
      Object::set_value($val, 'iso18626Address', $row['ISO18626_ADDRESS']);
      Object::set_value($val, 'iso18626Password', $row['ISO18626_PASSWORD']);
    }
  }

  /** \brief add z39.50 ill data to result
   *
   * @param buf (object) - structure for the result
   * @param row (array) - one result array from the DB
   */
  private function set_z3950Ill(&$buf, $row, $all_fields = TRUE) {
    $val = &$buf->z3950Ill->_value;
    if ($row['URL_ITEMORDER_BESTIL']) Object::set_value($val, 'z3950Address', $row['URL_ITEMORDER_BESTIL']);
    if ($row['ZBESTIL_GROUPID']) Object::set_value($val, 'z3950GroupId', $row['ZBESTIL_GROUPID']);
    if ($row['ZBESTIL_USERID']) Object::set_value($val, 'z3950UserId', $row['ZBESTIL_USERID']);
    if ($row['ZBESTIL_PASSW']) Object::set_value($val, 'z3950Password', $row['ZBESTIL_PASSW']);
    if ($all_fields) {
      Object::set_value($val, 'illRequest', 1);    // AHP dok?
      Object::set_value($val, 'illAnswer', ($row['ORS_ANSWER'] == 'z3950' ? '1' : '0'));
      Object::set_value($val, 'illShipped', ($row['ORS_SHIPPING'] == 'z3950' ? '1' : '0'));
      Object::set_value($val, 'illCancel', ($row['ORS_CANCEL'] == 'z3950' ? '1' : '0'));
      Object::set_value($val, 'illCancelReply', ($row['ORS_CANCELREPLY'] == 'z3950' ? '1' : '0'));
      Object::set_value($val, 'illCancelReplySynchronous', ($row['ORS_CANCEL_ANSWER_SYNCHRONIC'] == 'J' ? '1' : '0'));
      Object::set_value($val, 'illRenew', ($row['ORS_RENEW'] == 'z3950' ? '1' : '0'));
      Object::set_value($val, 'illRenewAnswer', ($row['ORS_RENEWANSWER'] == 'z3950' ? '1' : '0'));
      Object::set_value($val, 'illRenewAnswerSynchronous', ($row['ORS_RENEW_ANSWER_SYNCHRONIC'] == 'J' ? '1' : '0'));
    }
  }

  /** \brief parse status and status_eget from vip_fjernlaan 
   *
   * @param status (char) - single character status to translate
   * @return (string) - translated string
   */
  private function parse_will_send($status) {
    switch ($status) {
      case 'T': return('TEST');
      case 'J': return('YES');
      default: return('NO');
    }
  }

  /** \brief Fill pickupAgency with info from oracle
   *
   * used by findLibrary and pickupAgencyList to ensure identical structure
   * @param pickupAgency (object) - Structure for result
   * @param row (array) - one result array from the DB
   * @param ip_list (array) - List of ip-adresses for branchDomains
   */
  private function fill_pickupAgency(&$pickupAgency, $row, $ip_list = array()) {
    if (empty($pickupAgency)) {
      if (isset($row['VSN_NAVN'])) Object::set_value($pickupAgency, 'agencyName', $row['VSN_NAVN']);
      if (isset($row['VSN_BIB_NR'])) Object::set_value($pickupAgency, 'agencyId', self::normalize_agency($row['VSN_BIB_NR']));
      if (isset($row['VSN_BIB_TYPE'])) Object::set_value($pickupAgency, 'agencyType', self::set_agency_type($row['VSN_BIB_NR'], $row['VSN_BIB_TYPE']));
      if (isset($row['VSN_EMAIL'])) Object::set_value($pickupAgency, 'agencyEmail', $row['VSN_EMAIL']);
      if (isset($row['VSN_TLF_NR'])) Object::set_value($pickupAgency, 'agencyPhone', $row['VSN_TLF_NR']);
      if (isset($row['VSN_FAX_NR'])) Object::set_value($pickupAgency, 'agencyFax', $row['VSN_FAX_NR']);
      if (isset($row['VSN_CVR_NR'])) Object::set_value($pickupAgency, 'agencyCvrNumber', $row['VSN_CVR_NR']);
      if (isset($row['VSN_P_NR'])) Object::set_value($pickupAgency, 'agencyPNumber', $row['VSN_P_NR']);
      if (isset($row['VSN_EAN_NUMMER'])) Object::set_value($pickupAgency, 'agencyEanNumber', $row['VSN_EAN_NUMMER']);
      Object::set_value($pickupAgency, 'branchId', self::normalize_agency($row['BIB_NR']));
      Object::set_value($pickupAgency, 'branchType', $row['TYPE']);
      if (empty($pickupAgency->branchName)) {
        if ($row['NAVN']) {
          $pickupAgency->branchName[] = self::value_and_language($row['NAVN'], 'dan');
        }
        if ($row['NAVN_E']) {
          $pickupAgency->branchName[] = self::value_and_language($row['NAVN_E'], 'eng');
        }
      }
      if (empty($pickupAgency->branchShortName)) {
        if ($row['NAVN_K']) {
          $pickupAgency->branchShortName[] = self::value_and_language($row['NAVN_K'], 'dan');
        }
        if ($row['NAVN_E_K']) {
          $pickupAgency->branchShortName[] = self::value_and_language($row['NAVN_E_K'], 'eng');
        }
      }
      Object::set_value($pickupAgency, 'branchPhone', $row['TLF_NR']);
      Object::set_value($pickupAgency, 'branchEmail', $row['EMAIL']);
      if ($row['SVAR_EMAIL']) Object::set_value($pickupAgency, 'branchIllEmail', $row['SVAR_EMAIL']);
      Object::set_value($pickupAgency, 'branchIsAgency', ($row['FILIAL_VSN'] == 'J' ? 1 : 0));
      if ($row['BADR']) Object::set_value($pickupAgency, 'postalAddress', $row['BADR']);
      if ($row['BPOSTNR']) Object::set_value($pickupAgency, 'postalCode', $row['BPOSTNR']);
      if ($row['BCITY']) Object::set_value($pickupAgency, 'city', $row['BCITY']);
      if ($row['ISIL'] && ($row['BIB_NR'] >= 700000 && $row['BIB_NR'] <= 899999)) Object::set_value($pickupAgency, 'isil', $row['ISIL']);
      if ($row['KNUDEPUNKT']) Object::set_value($pickupAgency, 'junction', self::normalize_agency($row['KNUDEPUNKT']));
      if ($row['P_NR']) Object::set_value($pickupAgency, 'branchPNumber', self::normalize_agency($row['P_NR']));
      if ($row['UNI_C_NR']) Object::set_value($pickupAgency, 'branchStilNumber', $row['UNI_C_NR']);
      if ($row['URL_BIB_KAT']) Object::set_value($pickupAgency, 'branchCatalogueUrl', $row['URL_BIB_KAT']);
      if ($row['URL_VIDERESTIL']) Object::set_value($pickupAgency, 'lookupUrl', $row['URL_VIDERESTIL']);
      if ($row['URL_HOMEPAGE']) Object::set_value($pickupAgency, 'branchWebsiteUrl', $row['URL_HOMEPAGE']);
      if ($row['URL_SERV_DKL']) Object::set_value($pickupAgency, 'serviceDeclarationUrl', $row['URL_SERV_DKL']);
      if ($row['URL_BEST_BLANKET']) Object::set_value($pickupAgency, 'registrationFormUrl', $row['URL_BEST_BLANKET']);
      if ($row['URL_BEST_BLANKET_TEXT']) Object::set_value($pickupAgency, 'registrationFormUrlText', $row['URL_BEST_BLANKET_TEXT']);
      if ($row['URL_PAYMENT']) Object::set_value($pickupAgency, 'paymentUrl', $row['URL_PAYMENT']);
      if ($row['URL_LAANERSTATUS']) Object::set_value($pickupAgency, 'userStatusUrl', $row['URL_LAANERSTATUS']);
      if ($row['SUPPORT_EMAIL']) Object::set_value($pickupAgency, 'librarydkSupportEmail', $row['SUPPORT_EMAIL']);
      if ($row['SUPPORT_TLF']) Object::set_value($pickupAgency, 'librarydkSupportPhone', $row['SUPPORT_TLF']);
    }
    if ($row['HOLDEPLADS'])
      $pickupAgency->agencySubdivision[]->_value = $row['HOLDEPLADS'];
    if (empty($pickupAgency->openingHours) && ($row['AABN_TID'] || $row['AABN_TID_E'])) {
      if ($row['AABN_TID']) {
        $pickupAgency->openingHours[] = self::value_and_language($row['AABN_TID'], 'dan');
      }
      if ($row['AABN_TID_E']) {
        $pickupAgency->openingHours[] = self::value_and_language($row['AABN_TID_E'], 'eng');
      }
    }
    Object::set_value($pickupAgency, 'temporarilyClosed', ($row['BEST_MODT'] == 'J' ? 0 : 1));
    if ($row['BEST_MODT'] == 'L'
        && empty($pickupAgency->temporarilyClosedReason)
        && ($row['BEST_MODT_LUK'] || $row['BEST_MODT_LUK_ENG'])) {
      if ($row['BEST_MODT_LUK']) {
        $pickupAgency->temporarilyClosedReason[] = self::value_and_language($row['BEST_MODT_LUK'], 'dan');
      }
      if ($row['BEST_MODT_LUK_ENG']) {
        $pickupAgency->temporarilyClosedReason[] = self::value_and_language($row['BEST_MODT_LUK_ENG'], 'eng');
      }
    }
    if (empty($pickupAgency->illOrderReceiptText)) {
      if ($row['KVT_TEKST_FJL']) {
        $pickupAgency->illOrderReceiptText[] = self::value_and_language($row['KVT_TEKST_FJL'], 'dan');
      }
      if ($row['KVT_TEKST_FJL_E']) {
        $pickupAgency->illOrderReceiptText[] = self::value_and_language($row['KVT_TEKST_FJL_E'], 'eng');
      }
    }
    Object::set_value($pickupAgency, 'pickupAllowed', ($row['BEST_MODT'] == 'J' ? '1' : '0'));
    if ($row['DELETE_MARK']) {
      Object::set_value($pickupAgency, 'branchStatus', $row['DELETE_MARK']);
    }
    Object::set_value($pickupAgency, 'ncipLookupUser', ($row['NCIP_LOOKUP_USER'] == 'J' ? 1 : '0'));
    Object::set_value($pickupAgency, 'ncipRenewOrder', ($row['NCIP_RENEW'] == 'J' ? '1' : '0'));
    Object::set_value($pickupAgency, 'ncipCancelOrder', ($row['NCIP_CANCEL'] == 'J' ? '1' : '0'));
    Object::set_value($pickupAgency, 'ncipUpdateOrder', ($row['NCIP_UPDATE_REQUEST'] == 'J' ? '1' : '0'));
    if ($row['NCIP_ADDRESS']) {
      Object::set_value($pickupAgency, 'ncipServerAddress', $row['NCIP_ADDRESS']);
    }
    if ($row['NCIP_PASSWORD']) {
      Object::set_value($pickupAgency, 'ncipPassword', $row['NCIP_PASSWORD']);
    }
    if (is_array($ip_list)) {
      natsort($ip_list);
      foreach ($ip_list as $ip) {
        Object::set_array_value($pickupAgency->branchDomains->_value, 'domain', $ip);
      }
    }
    if ($row['AFSAETNINGSBIBLIOTEK'])
      Object::set_value($pickupAgency, 'dropOffBranch', $row['AFSAETNINGSBIBLIOTEK']);
    if ($row['AFSAETNINGSNAVN_K'])
      Object::set_value($pickupAgency, 'dropOffName', $row['AFSAETNINGSNAVN_K']);
    if ($last_date = max($row['DATO'], $row['BS_DATO'], $row['VSN_DATO']))
      Object::set_value($pickupAgency, 'lastUpdated', $last_date);
    Object::set_value($pickupAgency, 'isOclcRsLibrary', ($row['OCLC_SYMBOL'] == 'J' ? '1' : '0'));
    Object::set_value($pickupAgency, 'stateAndUniversityLibraryCopyService', ($row['SB_KOPIBESTIL'] == 'J' ? '1' : '0'));
    if ($row['LATITUDE'] || $row['LONGITUDE']) {
      Object::set_value($pickupAgency->geolocation->_value, 'latitude', str_replace(',', '.', $row['LATITUDE']));
      Object::set_value($pickupAgency->geolocation->_value, 'longitude', str_replace(',', '.', $row['LONGITUDE']));
      if ($row['DISTANCE']) {
        Object::set_value($pickupAgency->geolocation->_value, 'distanceInMeter', round(floatval(str_replace(',', '.', $row['DISTANCE']))));
      }
    }

    return;
  }

  /** \brief Check if agencyType should be replaced by ini setting
   *
   * @param agency_id (string)
   * @param agency_type (string) - agency type as set in VIP base
   * @return (string) - 
   */
  private function set_agency_type($agency_id, $agency_type) {
    static $agency_type_override;
    if (!isset($agency_type_override)) {
      $agency_type_override = $this->config->get_value('agencyTypeOverride', 'setup');
    }
    return ($agency_type_override[$agency_id] ? $agency_type_override[$agency_id] : $agency_type);
  }

  /** \brief return an xs:boolean 
   *
   * @param ip (char) - character to test
   * @return (integer) - 1 for true and 0 for false
   */
  private function J_is_true($ch) {
    return ($ch === 'J' ? 1 : 0);
  }

  /** \brief Check if a given requester (auth and ip) is a trusted server/client
   *
   * @param auth (object) - Authentication object of the client
   * @param ip (string) - Client ip-address
   * @return (boolean) - TRUE if the client has right 552
   */
  private function trusted_culr_ip($auth, $ip) {
    $fors = new aaa($this->config->get_section('aaa'));
    $fors->init_rights($auth->userIdAut->_value, $auth->groupIdAut->_value, $auth->passwordAut->_value, $ip);
    return $fors->has_right('netpunkt.dk', 552);
  }

  /** \brief set a node and its language attribute
   * @param val (string) - value of the object
   * @param lang (string) - language for the attribute
   * @return (object) - with element _value and language attribute
   *
   */
  private function value_and_language($val, $lang) {
    $ret = new stdClass();
    $ret->_value = $val;
    Object::set_value($ret->_attributes, 'language', $lang);
    return $ret;
  }

  /** \brief
   * @param id (string) the library number, like 710100
   * @return (string) - $id numeric aligned align to 6 digits
   */
  private function normalize_agency($id) {
    return is_numeric($id) ? sprintf('%06s', $id) : $id;
  }

  /** \brief Removes anything but digits
   * @param id (string) the ISIL number, like DK-710100
   * @return (string) only digits, so something like DK-710100 returns 710100
   */
  private function strip_agency($id) {
    return preg_replace('/\D/', '', $id);
  }

  /** \brief Removes NL(ascii 10) and CR (ascii 13)
   * @param arr (array of strings)
   */
  private function sanitize_array(&$arr) {
    if (is_array($arr)) {
      foreach ($arr as $key => $val) {
        if (is_scalar($val))
          $arr[$key] = str_replace("\r", ' ', str_replace("\n", ' ', $val));
      }
    }
  }

  /** \brief converts an xs:boolean to a php boolean
   * @param str (string)
   * @return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

  /** \brief
   *  return change array to string. For cache key
   *
   * @param mix (object or array of objects) 
   * @param glue (string) Seperator between the elements
   * @return (string) the concatenated string
   */
  private function stringiefy($mix, $glue = '') {
    if (is_array($mix)) {
      $ret = array();
      foreach ($mix as $m) {
        $ret[] = $m->_value;
      }
      return implode($glue, $ret);
    } 
    else
      return $mix->_value;
  }

  /** \brief makes a regular expression to match single_words in the DB, using ? as truncation
   *
   * select navn from vip where regexp_like(navn, '[ ,.;:]bibliotek[ .,;:$]');
   * select bib_nr, navn from vip where regexp_like(lower(navn), '(^|[ ,.;:])krystal[a-zæøå]*([ .,;:]|$)');
   *
   * @param par (string) the string to eventually locate in the DB 
   * @return (string) the DB like expression
   */
  private function build_regexp_like($par) {
    return '(^|[ ,.;:])' . str_replace('?', '[a-zæøå0-9]*', $par) . '([ .,;:]|$)';
  }

  /** \brief alters the timeout for cahce invalidation
   *
   * @param expire (integer) Number of seconds to cache stuff
   */
  private function set_cache_expire($expire) {
    if (!is_null($expire)) {
      $this->cache->set_expire((int) $expire);
    }
  }

}

/**
 *   MAIN
 */

$ws=new openAgency();
$ws->handle_request();

?>

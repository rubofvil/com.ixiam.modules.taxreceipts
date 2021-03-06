<?php

require_once('CRM/Contribute/Form/Task.php');

/**
 * This class provides the common functionality for issuing CDN Tax Receipts for
 * one or a group of contact ids.
 */
class CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 1000;

  private $_receipts;

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {

    //check for permission to edit contributions
    if ( ! CRM_Core_Permission::check('issue cdn tax receipts') ) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page'));
    }

    parent::preProcess();

    $receipts = array( 'original'  => array('email' => 0, 'print' => 0),
                       'duplicate' => array('email' => 0, 'print' => 0), );

    // count and categorize contributions
    foreach ( $this->_contributionIds as $id ) {
      if ( cdntaxreceipts_eligibleForReceipt($id) ) {
        list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($id);
        $key = empty($issued_on) ? 'original' : 'duplicate';
        list( $method, $email ) = cdntaxreceipts_sendMethodForContribution($id);
        $receipts[$key][$method]++;
      }
    }

    $this->_receipts = $receipts;
    //echo convert(memory_get_usage(true)); // 123 kb
  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

    CRM_Utils_System::setTitle(ts('Issue Tax Receipts'));

    // assign the counts
    $receipts = $this->_receipts;
    $originalTotal = $receipts['original']['print'] + $receipts['original']['email'];
    $duplicateTotal = $receipts['duplicate']['print'] + $receipts['duplicate']['email'];
    $receiptTotal = $originalTotal + $duplicateTotal;
    $this->assign('receiptCount', $receipts);
    $this->assign('originalTotal', $originalTotal);
    $this->assign('duplicateTotal', $duplicateTotal);
    $this->assign('receiptTotal', $receiptTotal);

    // add radio buttons
    $this->addElement('radio', 'receipt_option', NULL, ts('Issue tax receipts for the %1 unreceipted contributions only.', array(1=>$originalTotal)), 'original_only');
    $this->addElement('radio', 'receipt_option', NULL, ts('Issue tax receipts for all %1 contributions. Previously-receipted contributions will be marked \'duplicate\'.', array(1=>$receiptTotal)), 'include_duplicates');
    $this->addRule('receipt_option', ts('Selection required'), 'required');

    $this->add('checkbox', 'is_preview', ts('Run in preview mode?'));

    $buttons = array(
      array(
        'type' => 'cancel',
        'name' => ts('Back'),
      ),
      array(
        'type' => 'next',
        'name' => ts('Issue Tax Receipts'),
        'isDefault' => TRUE,
        'js' => array('onclick' => "return submitOnce(this,'{$this->_name}','" . ts('Processing') . "');"),
      ),
    );
    $this->addButtons($buttons);

  }

  function setDefaultValues() {
    return array('receipt_option' => 'original_only');
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return None
   */

  function postProcess() {

    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    // Issue 1895204: Turn off geocoding to avoid hitting Google API limits
    $config =& CRM_Core_Config::singleton();
    $oldGeocode = $config->geocodeMethod;
    unset($config->geocodeMethod);

    $params = $this->controller->exportValues($this->_name);

    $originalOnly = FALSE;
    if ($params['receipt_option'] == 'original_only') {
      $originalOnly = TRUE;
    }

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }       

    $emailCount = 0;
    $printCount = 0;
    $failCount = 0;

    $file_to_zip = array();

    foreach ($this->_contributionIds as $item => $contributionId) {      

      if ( $emailCount + $printCount + $failCount >= self::MAX_RECEIPT_COUNT ) {
        $status = ts('Maximum of %1 tax receipt(s) were sent. Please repeat to continue processing.', array(1=>self::MAX_RECEIPT_COUNT));
        CRM_Core_Session::setStatus($status, '', 'info');
        break;
      }

      // 1. Load Contribution information
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionId;
      if ( ! $contribution->find( TRUE ) ) {
        CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution id." );
      }

      // 2. If Contribution is eligible for receipting, issue the tax receipt.  Otherwise ignore.
      if ( cdntaxreceipts_eligibleForReceipt($contribution->id) ) {

        list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);
        if ( empty($issued_on) || ! $originalOnly ) {          
          list( $ret, $method, $path_pdf ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrinting, $previewMode );

          
          if(isset($path_pdf) && $path_pdf != ""){
            $file_to_zip[] = $path_pdf;
          }

          if ( $ret == 0 ) {
            $failCount++;
          }
          elseif ( $method == 'email' ) {
            $emailCount++;
          }
          else {
            $printCount++;
          }

        }
      }      
    }    

    // 3. Set session status
    if ( $previewMode ) {
      $status = ts('%1 tax receipt(s) have been previewed.  No receipts have been issued.', array(1=>$printCount));
      CRM_Core_Session::setStatus($status, '', 'success');
    }
    else {
      $status = ts('%1 tax receipt(s) were sent by email.', array(1=>$emailCount));
      CRM_Core_Session::setStatus($status, '', 'success');
      $status = ts('%1 tax receipt(s) need to be printed.', array(1=>$printCount));
      CRM_Core_Session::setStatus($status, '', 'success');
    }

    if ( $failCount > 0 ) {
      $status = ts('%1 tax receipt(s) failed to process.', array(1=>$failCount));
      CRM_Core_Session::setStatus($status, '', 'error');
    }

    // Issue 1895204: Reset geocoding
    $config->geocodeMethod = $oldGeocode;

    // 4. send the collected PDF for download
    $zip = new ZipArchive();
    
    $filename = $config->customFileUploadDir . date("YmdHis") .  "zip_receipt.zip" ;
    if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
      exit("cannot open <$filename>\n");
    }

    foreach ($file_to_zip as $key => $file_path) {
      $new_filename = substr($file_path,strrpos($file_path,'/') + 1);
      $zip->addFile($file_path, $new_filename);
    }
    $zip->close();
    
    

    if(!empty($filename)){
      $config = CRM_Core_Config::singleton();
      if (file_exists($filename)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename='.basename($filename));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filename));
        ob_clean();
        flush();
        readfile($filename);
        exit;
      }
    }
 
  }
}


function convert($size)
 {
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
 }


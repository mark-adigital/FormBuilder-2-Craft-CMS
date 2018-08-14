<?php
namespace Craft;

class FormBuilder2_EntryController extends BaseController
{

  protected $allowAnonymous = true;

  /**
   * Entries Index
   *
   */
  public function actionEntriesIndex()
  {
    $formItems = fb()->forms->getAllForms();
    $settings = craft()->plugins->getPlugin('FormBuilder2')->getSettings();
    $plugins = craft()->plugins->getPlugin('FormBuilder2');

    $variables['title']       = 'FormBuilder2';
    $variables['formItems']   = $formItems;
    $variables['settings']    = $settings;
    $variables['navigation']  = $this->navigation();

    return $this->renderTemplate('formbuilder2/entries/index', $variables);
  }

  /**
   * View/Edit Entry
   *
   */
  public function actionViewEntry(array $variables = array())
  {
    $entry = fb()->entries->getSubmissionById($variables['entryId']);

    if (empty($entry)) { throw new HttpException(404); }

    $files = '';
    if ($entry->files) {
      $files = array();
      foreach ($entry->files as $key => $value) {
        $files[] = craft()->assets->getFileById($value);
      }
    }

    $settings = craft()->plugins->getPlugin('FormBuilder2')->getSettings();
    // Craft::dd($entry->getAttributes());
    $variables['settings']    = $settings;
    $variables['entry']       = $entry;
    $variables['title']       = 'FormBuilder2';
    $variables['form']        = fb()->forms->getFormById($entry->formId);
    $variables['files']       = $files;
    $variables['submission']  = $entry->submission;
    $variables['navigation']  = $this->navigation();

    $this->renderTemplate('formbuilder2/entries/_view', $variables);
  }

  /**
   * Submit Entry
   *
   */
  public function actionSubmitEntry()
  {
    $this->requirePostRequest();

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // VARIABLES
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    $files                    = array();
    $fileLimit                = false;
    $ajax                     = false;
    $passedValidation         = true;
    $validationErrors         = array();
    $submissionErrorMessage   = array();
    $customSuccessMessage     = '';
    $customErrorMessage       = '';
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FORM
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    $form = fb()->entries->getFormByHandle(craft()->request->getPost('formHandle'));
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++


    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FORM SUBMISSION
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    $formFields = $form->fieldLayout->getFieldLayout()->getFields(); // Get all form fields
    $submission = craft()->request->getPost(); // Get all values from the submitted form
    $submissionData = $this->filterSubmissionKeys($submission); // Fillter out unused submission data
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FORM ATTRIBUTES
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    $attributes                   = $form->getAttributes();
    $formSettings                 = $attributes['formSettings'];
    $spamProtectionSettings       = $attributes['spamProtectionSettings'];
    $messageSettings              = $attributes['messageSettings'];
    $notificationSettings         = $attributes['notificationSettings'];
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FORM SETTINGS ||| (1) Custom Redirect, (2) File Uploads, (3) Ajax Submissions
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // (1) Custom Redirect
    if ($formSettings['formRedirect']['customRedirect'] != '') {
      $redirectUrl = $formSettings['formRedirect']['customRedirectUrl'];
    }

    // (2) File Uploads
    if ($formSettings['hasFileUploads'] == '1') {
      foreach ($formFields as $key => $value) {
        $field = $value->getField();
        switch ($field->type) {
          case 'Assets':

            $uploadedFiles = UploadedFile::getInstancesByName($field->handle);
            $allowedKinds = array();

            if ($field->settings['restrictFiles']) {
              $allowedKinds = $field->settings['allowedKinds'];
            }

            if ($field->settings['limit']) {
                $fileLimit = (int)$field->settings['limit'];
            }

            if ($allowedKinds) {
                $folder = craft()->assets->getFolderById($field->settings['defaultUploadLocationSource']);

                foreach ($uploadedFiles as $file) {
                  $fileKind = IOHelper::getFileKind(IOHelper::getExtension($file->getName()));
                  if (in_array($fileKind, $allowedKinds)) {
                    $files[] = array(
                      'folderId' => $folder->id,
                      'sourceId' => $folder->sourceId,
                      'filename' => $file->getName(),
                      'location' => $file->getTempName(),
                      'type'     => $file->getType(),
                      'kind'     => $fileKind
                    );
                  } else {
                    $submissionErrorMessage[] = Craft::t('File type is not allowed!');
                  }
                }
            } else {
                $folder = craft()->assets->getFolderById($field->settings['defaultUploadLocationSource']);

                foreach ($uploadedFiles as $file) {
                  $fileKind = IOHelper::getFileKind(IOHelper::getExtension($file->getName()));
                    $files[] = array(
                      'folderId' => $folder->id,
                      'sourceId' => $folder->sourceId,
                      'filename' => $file->getName(),
                      'location' => $file->getTempName(),
                      'type'     => $file->getType(),
                      'kind'     => $fileKind
                    );
                }
            }
          break;
        }
      }

      if ($fileLimit) {
          if (count($files) > $fileLimit) {
              $submissionErrorMessage[] = Craft::t('Only '. $fileLimit .' file is allowed!');
          }
      }
    }

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FORM CUSTOM MESSAGES ||| (1) Success Message (2) Error Message
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // (1) Success Message
    $customSuccessMessage = $messageSettings['successMessage'] ? $messageSettings['successMessage'] : Craft::t('Submission was successful.');
    // (2) Error Message
    $customErrorMessage = $messageSettings['errorMessage'] ? $messageSettings['errorMessage'] : Craft::t('There was a problem with your submission.');
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // (3) Ajax Submissions
    if ($formSettings['ajaxSubmit'] == '1') {
      $this->requireAjaxRequest();
      $ajax = true;
    }
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FORM SPAM PROTECTION ||| (1) Timed Method (2) Honeypot Method
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // (1) Timed Method
    if ($spamProtectionSettings['spamTimeMethod'] == '1') {
      $formSubmissionTime = (int)craft()->request->getPost('spamTimeMethod');
      $submissionDuration = time() - $formSubmissionTime;
      $allowedTime = (int)$spamProtectionSettings['spamTimeMethodTime'];
      if ($submissionDuration < $allowedTime) {
        if ($ajax) {
          $this->returnJson(array(
            'success' => false,
            'validationErrors' => array(Craft::t('You submitted too fast, you are robot!')),
            'customErrorMessage' => $customErrorMessage
          ));
        } else {
          $spamTimedMethod = false;
          $submissionErrorMessage[] = Craft::t('You submitted too fast, you are robot!');
        }
      } else {
        $spamTimedMethod = true;
      }
    } else {
      $spamTimedMethod = true;
    }

    // (2) Honeypot Method
    if ($spamProtectionSettings['spamHoneypotMethod'] == '1') {
      $honeypotField = craft()->request->getPost('email-address-new');
      if ($honeypotField != '') {
        if ($ajax) {
          $this->returnJson(array(
            'success' => false,
            'validationErrors' => array(Craft::t('You tried the honey, you are robot bear!')),
            'customErrorMessage' => $customErrorMessage
          ));
        } else {
          $spamHoneypotMethod = false;
          $submissionErrorMessage[] = Craft::t('You tried the honey, you are robot bear!');
        }
      } else {
        $spamHoneypotMethod = true;
      }
    } else {
      $spamHoneypotMethod = true;
    }
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // NEW FORM MODEL
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    $submissionEntry                  = new FormBuilder2_EntryModel();
    $submissionEntry->formId          = $form->id;
    $submissionEntry->title           = $form->name;
    $submissionEntry->submission      = $submissionData;
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // FAILED SUBMISSION REDIRECT W/MESSAGES (Spam Protection)
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    if ($submissionErrorMessage) {
      craft()->userSession->setFlash('error', $customErrorMessage);
      craft()->urlManager->setRouteVariables(array(
        'errors' => $submissionErrorMessage
      ));
    }
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // VALIDATE SUBMISSION DATA
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    $validation = fb()->entries->validateEntry($form, $submissionData, $files);

    // if ($validation != '') {
    if (!empty($validation)) {
      if ($ajax) {
        $this->returnJson(array(
          'success' => false,
          'passedValidation' => false,
          'validationErrors' => $validation,
          'customErrorMessage' => $customErrorMessage
        ));
      } else {
        craft()->userSession->setFlash('error', $customErrorMessage);
        $passedValidation = false;
        return craft()->urlManager->setRouteVariables(array(
          'value' => $submissionData, // Pass filled in data back to form
          'errors' => $validation // Pass validation errors back to form
        ));
      }
    }
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++


    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // PROCESS SUBMISSION ENTRY
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    if (!$submissionErrorMessage && $passedValidation && $spamTimedMethod && $spamHoneypotMethod) {

      // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
      // FILE UPLOADS
      // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
      $fileIds = array();
      $fileCollection = array();
      $tempPath = array();
      if ($files) {
        foreach ($files as $key => $file) {
          $tempPath = AssetsHelper::getTempFilePath($file['filename']);
          move_uploaded_file($file['location'], $tempPath);
          $response = craft()->assets->insertFileByLocalPath($tempPath, $file['filename'], $file['folderId'], AssetConflictResolution::KeepBoth);
          $fileIds[] = $response->getDataItem('fileId');
          $fileCollection[] = array(
            'tempPath' => $tempPath,
            'filename' => $file['filename'],
            'type'     => $file['type']
          );
        }
        $submissionEntry->files = $fileIds;
      }

      $submissionResponseId = fb()->entries->processSubmissionEntry($submissionEntry);

      if ($submissionResponseId) {
        // Notify Admin of Submission
        if (isset($notificationSettings['notifySubmission'])) {
          if ($notificationSettings['notifySubmission'] == '1') {
            $this->notifyAdminOfSubmission($submissionResponseId, $fileCollection, $form);
          }
        }

        // Notify Submitter of Submission
        if (isset($notificationSettings['notifySubmitter'])) {
          if ($notificationSettings['notifySubmitter'] == '1') {
            $this->notifySubmitterOfSubmission($submissionResponseId, $form);
          }
        }

        foreach ($fileCollection as $file) {
          IOHelper::deleteFile($file['tempPath'], true);
        }

        // Fire After Submission Complete Event
        Craft::import('plugins.formbuilder2.events.FormBuilder2_OnAfterSubmissionCompleteEvent');
        $event = new FormBuilder2_OnAfterSubmissionCompleteEvent(
            $this, array(
                'entryId' => $submissionResponseId
            )
        );
        craft()->formBuilder2->onAfterSubmissionCompleteEvent($event);

        // Successful Submission Messages
        if ($ajax) {
          $this->returnJson(array(
            'success' => true,
            'submissionData' => $submissionData,
            'customSuccessMessage' => $customSuccessMessage
          ));
        } else {
          craft()->userSession->setFlash('success', $customSuccessMessage);
          $cookie = new HttpCookie('formBuilder2SubmissionId', $submissionEntry->attributes['id']);
          craft()->request->getCookies()->add($cookie->name, $cookie);
          $this->redirectToPostedUrl();
        }
      } else {
        // Submission Error Messages
        if ($ajax) {
          $this->returnJson(array(
            'success' => false,
            'customErrorMessage' => $customErrorMessage,
            'errors' => $validation
          ));
        } else {
            craft()->userSession->setFlash('error', $customErrorMessage);
            return craft()->urlManager->setRouteVariables(array(
              'value' => $submissionData, // Pass filled in data back to form
              'errors' => $validation // Pass validation errors back to form
            ));
        }
      }
    }
    // ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

  }

  /**
   * Delete Submission
   *
   */
  public function actionDeleteSubmission()
  {
    $this->requirePostRequest();

    $entryId = craft()->request->getRequiredPost('entryId');

    if (craft()->elements->deleteElementById($entryId)) {
        craft()->userSession->setNotice(Craft::t('Entry deleted.'));
        $this->redirectToPostedUrl();
    } else {
        craft()->userSession->setError(Craft::t('Couldn’t delete entry.'));
    }
  }

  /**
   * Notify Admin of Submission
   *
   */
  protected function notifySubmitterOfSubmission($submissionResponseId, $form)
  {
    $submission       = fb()->entries->getSubmissionById($submissionResponseId);
    $files            = array();
    $postUploads      = $submission->files;
    $postData         = $submission->submission;
    $postData         = $this->filterSubmissionKeys($postData);

    $attributes             = $form->getAttributes();
    $formSettings           = $attributes['formSettings'];
    $notificationSettings   = $attributes['notificationSettings'];

    $variables['form']                  = $form;
    $variables['files']                 = $files;
    $variables['formSettings']          = $formSettings;
    $variables['emailSettings']         = $notificationSettings['emailSettings'];
    $variables['notificationSettings']  = $notificationSettings;
    $variables['templateSettings']      = $notificationSettings['emailTemplate'];
    $variables['sendSubmission']        = $notificationSettings['emailSettings']['sendSubmitterSubmissionData'];
    $emailField                         = $notificationSettings['submitterEmail'];
    $variables['data']                  = $postData;

    if ($notificationSettings['emailTemplateSubmitter'] && $notificationSettings['emailTemplateSubmitter'] != '') {
      $template = fb()->templates->getTemplateByHandle($notificationSettings['emailTemplateSubmitter']);
      $variables['template'] = $template;
    }

    $oldPath = craft()->templates->getTemplatesPath();
    craft()->templates->setTemplatesPath(craft()->path->getPluginsPath());
    $message  = craft()->templates->render('formbuilder2/templates/email/layouts/html', $variables);
    craft()->templates->setTemplatesPath($oldPath);

    // Email
    $toEmail = $postData[$emailField];

    if (fb()->entries->sendEmailNotificationToSubmitter($form, $message, true, $toEmail)) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Notify Admin of Submission
   *
   */
  protected function notifyAdminOfSubmission($submissionResponseId, $fileCollection, $form)
  {
    $submission       = fb()->entries->getSubmissionById($submissionResponseId);
    $files            = '';
    $postUploads      = $submission->files;
    $postData         = $submission->submission;
    $postData         = $this->filterSubmissionKeys($postData);

    // Uploaded Files
    if ($postUploads) {
      foreach ($postUploads as $key => $id) {
        $criteria         = craft()->elements->getCriteria(ElementType::Asset);
        $criteria->id     = $id;
        $criteria->limit  = 1;
        $files          = $criteria->find();
      }
    }

    $attributes             = $form->getAttributes();
    $formSettings           = $attributes['formSettings'];
    $notificationSettings   = $attributes['notificationSettings'];

    $variables['form']                  = $form;
    $variables['files']                 = $files;
    $variables['formSettings']          = $formSettings;
    $variables['emailSettings']         = $notificationSettings['emailSettings'];
    $variables['notificationSettings']  = $notificationSettings;
    $variables['templateSettings']      = isset($notificationSettings['emailTemplate']) ? $notificationSettings['emailTemplate'] : null;
    $variables['sendSubmission']        = $notificationSettings['emailSettings']['sendSubmissionData'];
    $variables['data'] = $postData;

    if ($notificationSettings['emailTemplate'] && $notificationSettings['emailTemplate'] != '') {
      $template = fb()->templates->getTemplateByHandle($notificationSettings['emailTemplate']);
      $variables['template'] = $template;
    }

    $customSubject = '';
    // Custom Subject
    if (isset($variables['emailSettings']['emailSubject'])) {
        $customSubject = craft()->templates->renderObjectTemplate($variables['emailSettings']['emailSubject'], $postData);
    }

    // Overwrite custom subject
    if (isset($notificationSettings['customSubject'])) {
      if ($notificationSettings['customSubject'] == '1') {
        $customSubjectField = $notificationSettings['customSubjectLine'];
        $customSubject = $postData[$customSubjectField];
      }
    }

    $oldPath = craft()->templates->getTemplatesPath();
    craft()->templates->setTemplatesPath(craft()->path->getPluginsPath());
    $message  = craft()->templates->render('formbuilder2/templates/email/layouts/html', $variables);
    craft()->templates->setTemplatesPath($oldPath);

    // Custom Emails
    $customEmail = '';
    if ($notificationSettings['customEmailField']) {
      $customEmail = $postData[$notificationSettings['customEmailField']];
    }

    if (fb()->entries->sendEmailNotification($form, $fileCollection, $postData, $customEmail, $customSubject, $message, true, null)) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Filter Out Unused Post Submission
   *
   */
  protected function filterSubmissionKeys($submission)
  {
    $filterKeys = array(
      'action',
      'redirect',
      'formRedirect',
      'formHandle',
      'spamTimeMethod',
      'email-address-new',
    );
    if (is_array($submission)) {
      foreach ($submission as $k => $v) {
        if (in_array($k, $filterKeys)) {
          unset($submission[$k]);
        }
      }
    }
    return $submission;
  }

  /**
   * Sidebar Navigation
   *
   */
  public function navigation()
  {
    $navigationSections = array(
      array(
        'heading' => Craft::t('Menu'),
        'nav'     => array(
          array(
            'label' => Craft::t('Dashboard'),
            'icon'  => 'tachometer',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2'),
          ),
          array(
            'label' => Craft::t('Forms'),
            'icon'  => 'list-alt',
            'extra' => fb()->forms->getTotalForms(),
            'url'   => UrlHelper::getCpUrl('formbuilder2/forms'),
          ),
          array(
            'label' => Craft::t('Entries'),
            'icon'  => 'file-text-o',
            'extra' => fb()->entries->getTotalEntries(),
            'url'   => UrlHelper::getCpUrl('formbuilder2/entries'),
          ),
        )
      ),
      array(
        'heading' => Craft::t('Quick Links'),
        'nav'     => array(
          array(
            'label' => Craft::t('Create New Form'),
            'icon'  => 'pencil-square-o',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2/forms/new'),
          ),
        )
      ),
      array(
        'heading' => Craft::t('Tools'),
        'nav'     => array(
          array(
            'label' => Craft::t('Export'),
            'icon'  => 'share-square-o',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2/tools/export'),
          ),
          array(
            'label' => Craft::t('Configuration'),
            'icon'  => 'sliders',
            'extra' => '',
            'url'   => UrlHelper::getCpUrl('formbuilder2/tools/configuration'),
          ),
        )
      ),
    );
    return $navigationSections;
  }

  /**
   * Download all entry files
   */
  public function actionDownloadAllFiles()
  {
      $this->requireAjaxRequest();

      if (ini_get('allow_url_fopen')) {
          $fileIds = craft()->request->getRequiredPost('ids');
          $formId = craft()->request->getRequiredPost('formId');
          $files = array();

          foreach ($fileIds as $id) {
              $files[] = craft()->assets->getFileById($id);
          }

          $zipname = craft()->path->getTempPath().'SubmissionFiles-'.$formId.'.zip';
          $zip = new \ZipArchive();
          $zip->open($zipname, \ZipArchive::CREATE);

          foreach ($files as $file) {
              $zip->addFromString($file->filename, IOHelper::getFileContents($file->url));
          }

          $filePath = $zip->filename;
          $zip->close();

          if ($filePath == $zipname) {
              $this->returnJson(array(
                  'success' => true,
                  'message' => 'Download Complete.',
                  'filePath' => $filePath
              ));
          }
      } else {
          $this->returnJson(array(
              'success' => false,
              'message' => 'Cannot download all files, `allow_url_fopen` must be enabled.'
          ));
      }
  }

  /**
   * Download files
   */
  public function actionDownloadFiles()
  {
      $filePath = craft()->request->query['filePath'];
      craft()->request->sendFile(IOHelper::getFileName($filePath), IOHelper::getFileContents($filePath), array('forceDownload' => true));
  }

}

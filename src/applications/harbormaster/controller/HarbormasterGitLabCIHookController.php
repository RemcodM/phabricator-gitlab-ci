<?php

final class HarbormasterGitLabCIHookController
  extends HarbormasterController {

  public function shouldRequireLogin() {
    return false;
  }

  /**
   * @phutil-external-symbol class PhabricatorStartup
   */
  public function handleRequest(AphrontRequest $request) {
    $raw_body = PhabricatorStartup::getRawInput();
    $body = phutil_json_decode($raw_body);

    $object_kind = idx($body, 'object_kind');
    if ($object_kind != 'pipeline') {
      return $this->newHookResponse(pht('OK: Ignored object.'));
    }

    $object_attributes = idx($body, 'object_attributes');
    if (!is_array($object_attributes)) {
      throw new Exception(
        pht(
          'Expected "%s" property to contain a dictionary.',
          'object_attributes'));
    }

    $status = idx($object_attributes, 'status');
    if ($status != 'running' && $status != 'failed' && $status != 'success') {
      return $this->newHookResponse(pht('OK: Ignored status.'));
    }

    $variables = idx($object_attributes, 'variables');
    if (!is_array($variables)) {
      throw new Exception(
        pht(
          'Expected "%s" property to contain an array.',
          'object_attributes.variables'));
    }

    $target_phid = null;
    foreach ($variables as $variable) {
      $key = idx($variable, 'key');
      if ($key !== 'HARBORMASTER_BUILD_TARGET_PHID') {
        continue;
      }
      $value = idx($variable, 'value');
      if (!$value) {
        throw new Exception(
          pht(
            'Expected "%s" property to contain a value.',
            'object_attributes.variables.*.value'));
      }
      $target_phid = $value;
    }

    if (!$target_phid) {
      return $this->newHookResponse(pht('OK: No Harbormaster target PHID.'));
    }

    $viewer = PhabricatorUser::getOmnipotentUser();
    $target = id(new HarbormasterBuildTargetQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($target_phid))
      ->needBuildSteps(true)
      ->executeOne();
    if (!$target) {
      throw new Exception(
        pht(
          'Harbormaster build target "%s" does not exist.',
          $target_phid));
    }

    $step = $target->getBuildStep();
    $impl = $step->getStepImplementation();
    if (!($impl instanceof HarbormasterGitLabCIBuildStepImplementation)) {
      throw new Exception(
        pht(
          'Harbormaster build target "%s" is not a GitLab CI build step. '.
          'Only GitLab CI steps may be updated via the GitLab CI hook.',
          $target_phid));
    }

    $webhook_token = $impl->getSetting('webhook.token');
    $request_token = $request->getHTTPHeader('X-Gitlab-Token');

    if (!phutil_hashes_are_identical($webhook_token, $request_token)) {
      throw new Exception(
        pht(
          'GitLab CI request to target "%s" had the wrong authentication '.
          'token. The GitLab CI pipeline and Harbormaster build step must '.
          'be configured with the same token.',
          $target_phid));
    }

    switch ($state) {
      case 'running':
        $message_type = HarbormasterMessageType::MESSAGE_WORK;
        break;
      case 'success':
        $message_type = HarbormasterMessageType::MESSAGE_PASS;
        break;
      default:
        $message_type = HarbormasterMessageType::MESSAGE_FAIL;
        break;
    }

    $api_method = 'harbormaster.sendmessage';
    $api_params = array(
      'buildTargetPHID' => $target_phid,
      'type' => $message_type,
    );

    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

      id(new ConduitCall($api_method, $api_params))
        ->setUser($viewer)
        ->execute();

    unset($unguarded);

    return $this->newHookResponse(pht('OK: Processed event.'));
  }

  private function newHookResponse($message) {
    $response = new AphrontWebpageResponse();
    $response->setContent($message);
    return $response;
  }

}

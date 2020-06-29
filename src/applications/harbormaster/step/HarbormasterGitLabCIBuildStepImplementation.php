<?php

final class HarbormasterGitLabCIBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  public function getName() {
    return pht('Build with GitLab CI');
  }

  public function getGenericDescription() {
    return pht('Trigger a build in GitLab CI.');
  }

  public function getBuildStepGroupKey() {
    return HarbormasterExternalBuildStepGroup::GROUPKEY;
  }

  public function getDescription() {
    return pht('Run a build in GitLab CI.');
  }

  public function getEditInstructions() {
    $hook_uri = '/harbormaster/hook/gitlabci/';
    $hook_uri = PhabricatorEnv::getProductionURI($hook_uri);

    return pht(<<<EOTEXT
WARNING: This build step is new and experimental!

To build **revisions** with GitLab CI, they must:

  - belong to a tracked repository;
  - the repository must have a Staging Area configured;
  - you must configure a GitLab CI pipeline for that Staging Area;
  - the Staging Area must be hosted on GitLab CI; and
  - you must configure the webhook described below.

To build **commits** with GitLab CI, they must:

  - belong to a repository that is being imported from GitLab CI; and
  - you must configure a GitLab CI pipeline for that repository; and
  - you must configure the webhook described below.

API Token
=========

Please use an API token with `sudo` and `api` scopes. `sudo` allows Harbormaster to
run the build as the user who created the commit/differential.


Webhook Configuration
=====================

In {nav Settings > Webhooks} for your repository in GitLab CI, add a new **Webook**.

Use these settings:

  - **Webhook URL**: %s
  - **Token**: The "Webhook Token" field below and the "Secret Token" field in
    GitLab CI should both be set to the same nonempty value (any random secret).
  - **Trigger**: Only **Pipeline events** needs to be active.
  - **Enable SSL verification**: Can remain enabled.

Environment
===========

These variables will be available in the build environment:

| Variable | Description |
|----------|-------------|
| `HARBORMASTER_BUILD_TARGET_PHID` | PHID of the Build Target.
| `HARBORMASTER_BUILD_ID` | ID of the Build.
| `HARBORMASTER_BUILD_URL` | URL of the Build.
| `HARBORMASTER_BUILDABLE_ID` | ID of the Buildable.
| `HARBORMASTER_BUILDABLE_URL` | URL of the Buildable.
EOTEXT
    ,
    $hook_uri);
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      $this->logSilencedCall($build, $build_target, pht('GitLabCI'));
      throw new HarbormasterBuildFailureException();
    }

    $buildable = $build->getBuildable();

    $object = $buildable->getBuildableObject();
    if (!($object instanceof HarbormasterGitLabCIBuildableInterface)) {
      throw new Exception(
        pht('This object does not support builds with GitLab CI.'));
    }

    $gitlab_host = $this->getSetting('host');
    $gitlab_project_id = $this->getSetting('projectid');

    $credential_phid = $this->getSetting('token');
    $api_token = id(new PassphraseCredentialQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($credential_phid))
      ->needSecrets(true)
      ->executeOne();
    if (!$api_token) {
      throw new Exception(
        pht(
          'Unable to load API token ("%s")!',
          $credential_phid));
    }
    $gitlab_api_token = $api_token->getSecret()->openEnvelope();

    $engine = HarbormasterBuildableEngine::newForObject(
      $object,
      $viewer);

    $gitlab_sudo_id = null;
    $author_identity = $engine->getAuthorIdentity();
    if ($author_identity) {
      $identity_uri = urisprintf(
        'https://%s/api/v4/users',
        $gitlab_host);

      $identity_data = array(
        "search" => $author_identity->getIdentityEmailAddress()
      );
      $identity_json = phutil_json_encode($identity_data);

      $identity_future = id(new HTTPSFuture($identity_uri, $identity_json))
        ->setMethod('GET')
        ->addHeader('Content-Type', 'application/json')
        ->addHeader('Accept', 'application/json')
        ->addHeader('Authorization', "Bearer {$gitlab_api_token}")
        ->setTimeout(60);

      $this->resolveFutures(
        $build,
        $build_target,
        array($identity_future));

      $this->logHTTPResponse($build, $build_target, $identity_future, pht('GitLabCI'));

      list($identity_status, $identity_body) = $identity_future->resolve();
      if ($identity_status->isError()) {
        throw new HarbormasterBuildFailureException();
      }

      $identity_response = phutil_json_decode($identity_body);
      if (!is_array($identity_response)) {
        throw new Exception(
          pht('GitLab CI did not return an array for!'));
      }

      if (count($identity_response) == 1) {
        $identity = array_pop($identity_response);
        $identity_id = idx($identity, 'id');
        if (!$identity_id) {
          throw new Exception(
            pht(
              'GitLab CI did not return a "%s"!',
              'id'));
        }
        
        $gitlab_sudo_id = $identity_id;
      }
    }

    $pipeline_uri = urisprintf(
      'https://%s/api/v4/projects/%s/pipeline',
      $gitlab_host,
      $gitlab_project_id);

    $pipeline_data = array(
      'ref' => $object->getGitLabCIRef(),
      'variables' => array(
        array(
          'key' => 'HARBORMASTER_BUILD_TARGET_PHID',
          'value' => $build_target->getPHID()
        ),
        array(
          'key' => 'HARBORMASTER_BUILD_ID',
          'value' => $build->getID()
        ),
        array(
          'key' => 'HARBORMASTER_BUILD_URL',
          'value' => PhabricatorEnv::getProductionURI($build->getURI())
        ),
        array(
          'key' => 'HARBORMASTER_BUILDABLE_ID',
          'value' => $buildable->getID()
        ),
        array(
          'key' => 'HARBORMASTER_BUILDABLE_URL',
          'value' => PhabricatorEnv::getProductionURI($buildable->getURI())
        )
      ),
    );

    $pipeline_json = phutil_json_encode($pipeline_data);

    $pipeline_future = id(new HTTPSFuture($pipeline_uri, $pipeline_json))
      ->setMethod('POST')
      ->addHeader('Content-Type', 'application/json')
      ->addHeader('Accept', 'application/json')
      ->addHeader('Authorization', "Bearer {$gitlab_api_token}")
      ->setTimeout(60);
    if ($gitlab_sudo_id) {
      $pipeline_future = $pipeline_future->addHeader('Sudo', $gitlab_sudo_id);
    }

    $this->resolveFutures(
      $build,
      $build_target,
      array($pipeline_future));

    $this->logHTTPResponse($build, $build_target, $pipeline_future, pht('GitLabCI'));

    list($pipeline_status, $pipeline_body) = $pipeline_future->resolve();
    if ($pipeline_status->isError()) {
      throw new HarbormasterBuildFailureException();
    }

    $pipeline_response = phutil_json_decode($pipeline_body);

    $uri_key = 'web_url';
    $build_uri = idx($response, $uri_key);
    if (!$build_uri) {
      throw new Exception(
        pht(
          'GitLab CI did not return a "%s"!',
          $uri_key));
    }

    $target_phid = $build_target->getPHID();

    $api_method = 'harbormaster.createartifact';
    $api_params = array(
      'buildTargetPHID' => $target_phid,
      'artifactType' => HarbormasterURIArtifact::ARTIFACTCONST,
      'artifactKey' => 'gitlabci.uri',
      'artifactData' => array(
        'uri' => $build_uri,
        'name' => pht('View in GitLab CI'),
        'ui.external' => true,
      ),
    );

    id(new ConduitCall($api_method, $api_params))
      ->setUser($viewer)
      ->execute();
  }

  public function getFieldSpecifications() {
    return array(
      'host' => array(
        'name' => pht('GitLab Host'),
        'type' => 'text',
        'required' => true,
      ),
      'projectid' => array(
        'name' => pht('GitLab Project ID'),
        'type' => 'text',
        'required' => true,
      ),
      'token' => array(
        'name' => pht('API Token'),
        'type' => 'credential',
        'credential.type'
          => PassphraseTokenCredentialType::CREDENTIAL_TYPE,
        'credential.provides'
          => PassphraseTokenCredentialType::PROVIDES_TYPE,
        'required' => true,
      ),
      'webhook.token' => array(
        'name' => pht('Webhook Token'),
        'type' => 'text',
        'required' => true,
      ),
    );
  }

  public function supportsWaitForMessage() {
    return false;
  }

  public function shouldWaitForMessage(HarbormasterBuildTarget $target) {
    return true;
  }

}

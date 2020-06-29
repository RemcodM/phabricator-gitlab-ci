= GitLab CI Support for Phabricator

This branch contains some files and patches that can be used to add support for GitLab CI to Phabricator.

It adds a GitLab CI webhook listener to Phabricator and a GitLab CI build step for Habourmaster.

== Requirements
You need a GitLab version that supports version 4 of the API.

== Installation
Clone the repository and run:
```
% ./apply_patches.sh <PATH_TO_YOUR_PHABRICATOR_INSTALLATION>
```
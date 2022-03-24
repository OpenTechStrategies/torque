# Releasing

When releasing a new version, a few things need to happen:

* Update the variable in both the server and client, in
  * `__version__` in [torqueclient/torqueclient/version.py]
  * `__version__` in [django-torque/torque/version.py]
  * `version` attribute in [extenions/extension.json]
* commit the version update
* tag the release in git
* push to origin

* release the client to pypi (see [the official pypi documentation](https://packaging.python.org/en/latest/tutorials/packaging-projects/) for more information):
  * This requires a pypi login as well as access to the torqueclient project
  * Run `python3 -m build` from inside the `torqueclient` and `django-torque` directories
  * Upload to test pypi via `python3 -m twine upload --repository testpypi dist/*` from both those directories
  * Test it via `python3 -m pip install --index-url https://test.pypi.org/simple/ --no-deps torqueclient`
  * Upload it to the normal pypi `python3 -m twine upload dist/*` from both directories

* upload a new version of the extension to github via
  * create via `tar -cvzf Torque-${VERSION}.tar.gz -C extension/ Torque`
  * create a release from the tag at https://github.com/OpenTechStrategies/torque/releases/tag/${VERSION}
  * upload the tar.gz to that release
  * update https://www.mediawiki.org/wiki/Extension:Torque to point to the new release

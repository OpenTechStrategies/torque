# Releasing

When releasing a new version, a few things need to happen:

* Update the variable in both the server and client, in
  * `__version__` in [torqueclient/torqueclient/version.py]
  * `SERVER_VERSION` in [torquedata/core/utils.py]
* tag the release in git
* release the client to pypi (see [the official pypi documentation](https://packaging.python.org/en/latest/tutorials/packaging-projects/) for more information):
  * This requires a pypi login as well as access to the torqueclient project
  * Run `python3 -m build` from inside the `torqueclient` directory
  * Upload to test pypi via `python3 -m twine upload --repository testpypi dist/*`
  * Test it via `python3 -m pip install --index-url https://test.pypi.org/simple/ --no-deps torqueclient`
  * Upload it to the normal pypi `python3 -m twine upload dist/*`

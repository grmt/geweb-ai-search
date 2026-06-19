# Agent guidelines

1. Make sure when you write code that it satisfies sonarqube and linting rules

e.g. Cognitive Complexity of functions should not be too high (javascript:S3776)
The maximum authorized complexity is 15

2. Before running shell commands that depend on the runtime environment, verify whether the session is Linux, WSL, or Windows. Do not assume `/mnt/c/...` means WSL. Check `uname -a`, `pwd`, `which node`, and `node -p "process.platform"` when the execution context matters.

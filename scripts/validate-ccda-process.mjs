export function generatorFailureExitCode(status, stdout) {
    if (status !== 0) {
        return status ?? 1;
    }

    return stdout === '' ? 1 : null;
}

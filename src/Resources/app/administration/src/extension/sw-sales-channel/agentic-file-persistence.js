/** @private */
export async function saveAgenticFileConfigurations(configurations, repository, context) {
    const changedConfigurations = [...configurations].filter((configuration) => {
        return configuration.isNew() || repository.hasChanges(configuration);
    });

    if (changedConfigurations.length === 0) {
        return true;
    }

    const writes = await Promise.allSettled(
        changedConfigurations.map((configuration) => {
            return repository.save(configuration, context);
        }),
    );
    const savedConfigurations = changedConfigurations.filter((_configuration, index) => {
        return writes[index].status === 'fulfilled';
    });

    savedConfigurations.forEach((configuration) => {
        // Retrying after a failed reload must neither recreate the file nor
        // compare later edits against the state from before this write.
        configuration._isNew = false;
        Object.assign(configuration.getOrigin(), configuration.getDraft());
    });

    const reloads = await Promise.allSettled(
        savedConfigurations.map((configuration) => {
            return repository.get(configuration.id, context);
        }),
    );
    let allSaved = writes.every((result) => result.status === 'fulfilled');

    reloads.forEach((result) => {
        if (result.status !== 'fulfilled' || !result.value) {
            allSaved = false;
            return;
        }

        const savedConfiguration = result.value;
        const index = configurations.findIndex((configuration) => configuration.id === savedConfiguration.id);
        if (index !== -1) {
            configurations.splice(index, 1, savedConfiguration);
        }
    });

    return allSaved;
}

/** @private */
export function mergeAgenticFileDrafts(configurations, savedConfigurations, repository) {
    for (const configuration of configurations) {
        if (!configuration.isNew() && !repository.hasChanges(configuration)) {
            continue;
        }

        const savedConfiguration = savedConfigurations.find((file) => {
            return (
                file.fileFamily === configuration.fileFamily &&
                file.fileName.toLowerCase() === configuration.fileName.toLowerCase()
            );
        });
        if (savedConfiguration) {
            savedConfiguration.enabled = configuration.enabled;
            savedConfiguration.templateOverrides = configuration.templateOverrides;
        } else {
            savedConfigurations.push(configuration);
        }
    }
}

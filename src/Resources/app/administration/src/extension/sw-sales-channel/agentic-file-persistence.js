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

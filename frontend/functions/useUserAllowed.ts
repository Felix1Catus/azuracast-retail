import { GlobalPermissions } from "~/entities/ApiInterfaces.ts";
import { useAcaciaRadioUser } from "~/vendor/acacia.ts";

export function useUserAllowed() {
    const { permissions } = useAcaciaRadioUser();

    return {
        userAllowed: (permission: GlobalPermissions): boolean => {
            try {
                if (permissions.global.indexOf(GlobalPermissions.All) !== -1) {
                    return true;
                }

                return permissions.global.indexOf(permission) !== -1;
            } catch {
                return false;
            }
        },
    };
}

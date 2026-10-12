import { defineStore } from "pinia";
import { InjectionKey } from "vue";
import {
    VueAppGlobals,
    VueDashboardGlobals,
    VueUserGlobals,
} from "~/entities/ApiInterfaces.ts";
import injectRequired from "~/functions/injectRequired.ts";

export const globalConstantsKey = Symbol() as InjectionKey<VueAppGlobals>;

export const useAcaciaRadioStore = defineStore(
    "global-props",
    (): {
        props: VueAppGlobals;
    } => {
        return {
            props: injectRequired(globalConstantsKey),
        };
    },
);

export const useAcaciaRadio = (): VueAppGlobals => {
    const { props } = useAcaciaRadioStore();
    return props;
};

export const useAcaciaRadioDashboardGlobals = (): VueDashboardGlobals => {
    const { dashboardProps } = useAcaciaRadio();
    if (!dashboardProps) {
        throw new Error("Dashboard properties are undefined in this request.");
    }

    return dashboardProps;
};

export const useAcaciaRadioUser = (): VueUserGlobals => {
    const { user } = useAcaciaRadio();

    if (!user) {
        throw Error("User is not logged in.");
    }

    return user;
};

// Custom (Lebazone): pick a delivery zone automatically on a first visit, so the
// website opens straight onto the shop instead of a "Set delivery location"
// popup. The shopper can still change it any time from the header, and their
// real address is asked for at checkout.
//
// Which zone: NEXT_PUBLIC_DEFAULT_ZONE_SLUG if set and found, otherwise the
// first zone that has a drawn area. Returns false when nothing could be picked
// (no zones yet) so the caller can fall back to the location popup.
import * as api from "@/api/apiRoutes";
import { setCity } from "@/redux/slices/citySlice";
import { setSelectedZone } from "@/redux/slices/locationModalSlice";
import { nextZoneRequestSeq } from "@/utils/zoneRequestSeq";
import { getPolygonCenter } from "@/utils/helperFunction";

let inFlight: Promise<boolean> | null = null;

export const autoSelectDefaultZone = (dispatch: (a: any) => void): Promise<boolean> => {
  if (inFlight) return inFlight;
  inFlight = (async () => {
    try {
      const res = await api.getZones();
      const zones: any[] = (res?.data || [])
        .map((z: any) => ({ ...z, center: getPolygonCenter(z.polygon_boundary) }))
        .filter((z: any) => z.center && z.slug);
      if (!zones.length) return false;
      const wanted = (process.env.NEXT_PUBLIC_DEFAULT_ZONE_SLUG || "").trim();
      const zone = zones.find((z) => z.slug === wanted) || zones[0];

      // The polygon's average point can fall outside an oddly shaped zone, so
      // also try points pulled slightly inward from each corner.
      const pts = (zone.polygon_boundary || [])
        .map((p: any) => ({ lat: Number(p.lat ?? p[0]), lng: Number(p.lng ?? p[1]) }))
        .filter((p: any) => Number.isFinite(p.lat) && Number.isFinite(p.lng));
      const candidates = [zone.center, ...pts.slice(0, 8).map((p: any) => ({
        lat: p.lat + (zone.center.lat - p.lat) * 0.15,
        lng: p.lng + (zone.center.lng - p.lng) * 0.15,
      }))];
      let d: any = null;
      let lat = 0;
      let lng = 0;
      for (const c of candidates) {
        const r = await api.getZone({ latitude: c.lat, longitude: c.lng });
        if (r?.status == 1 && r?.data) {
          d = r.data;
          lat = c.lat;
          lng = c.lng;
          break;
        }
      }
      if (!d) return false;

      nextZoneRequestSeq();
      dispatch(
        setCity({
          data: {
            id: d.id,
            name: zone.name,
            state: d.state,
            slug: d.slug,
            formatted_address: zone.name,
            latitude: lat,
            longitude: lng,
            min_amount_for_free_delivery: d.min_amount_for_free_delivery,
            delivery_charge_method: d.delivery_charge_method,
            fixed_charge: d.fixed_charge,
            per_km_charge: d.per_km_charge,
            time_to_travel: d.time_to_travel,
            max_deliverable_distance: d.max_deliverable_distance,
            distance: d.distance,
            zone_id: d.zone_id,
            channel: d.channel,
          },
        }),
      );
      dispatch(setSelectedZone({ id: d.id, name: d.name || zone.name, slug: d.slug || zone.slug }));
      return true;
    } catch (e) {
      console.log("[defaultZone] could not auto-select a zone", e);
      return false;
    } finally {
      inFlight = null;
    }
  })();
  return inFlight;
};

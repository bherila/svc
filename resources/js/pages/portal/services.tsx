// Placeholder: the portal Services tab is built against these props (docs/service-packages.md, slice 3a).
type Service = { id: string; name: string; status: string; url: string };
type AddablePackage = { id: string; name: string; add_url: string | null };
type Props = { services: Service[]; addable_packages: AddablePackage[] | null };

export default function PortalServices({ services, addable_packages }: Props) {
    return (
        <div className="grid grid-cols-1 gap-4">
            <ul>
                {services.map((service) => (
                    <li key={service.id} className="wrap-anywhere">
                        <a href={service.url}>{service.name}</a>
                    </li>
                ))}
            </ul>
            {addable_packages === null ? null : (
                <ul>
                    {addable_packages.map((pkg) => (
                        <li key={pkg.id} className="wrap-anywhere">
                            {pkg.name}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
